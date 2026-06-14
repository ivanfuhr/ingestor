<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Contract\RowContext;
use Ivanfuhr\Ingestor\Driver\Persistence\Postgres\PostgresIdentifier;
use Ivanfuhr\Ingestor\Driver\Persistence\Postgres\PostgresProductionSwapper;
use Ivanfuhr\Ingestor\Driver\Persistence\Postgres\PostgresStageBootstrap;
use Ivanfuhr\Ingestor\Driver\Persistence\Postgres\PostgresStagingIngestor;
use Ivanfuhr\Ingestor\Driver\Persistence\Postgres\PostgresTableIntrospection;
use Ivanfuhr\Ingestor\Driver\Persistence\Postgres\StagingInsertBuffer;
use Ivanfuhr\Ingestor\Metrics\MetricsRecorder;
use Ivanfuhr\Ingestor\Stage\Stage;
use PDO;
use Throwable;

final readonly class PostgresDriver implements PersistenceDriver
{
    private const int DEFAULT_CHUNK_SIZE = 500;

    private PostgresIdentifier $identifiers;

    private PostgresTableIntrospection $introspection;

    private PostgresStageBootstrap $stageBootstrap;

    private PostgresStagingIngestor $stagingIngestor;

    private PostgresProductionSwapper $productionSwapper;

    public function __construct(
        private PDO $pdo,
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private SqlFailureMode $failureMode = SqlFailureMode::Fast,
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1.');
        }

        $this->identifiers = new PostgresIdentifier();
        $this->introspection = new PostgresTableIntrospection($this->pdo, $this->identifiers);
        $this->stageBootstrap = new PostgresStageBootstrap($this->pdo, $this->identifiers);
        $this->stagingIngestor = new PostgresStagingIngestor(
            $this->pdo,
            $this->chunkSize,
            $this->failureMode,
            $this->identifiers,
            $this->introspection,
        );
        $this->productionSwapper = new PostgresProductionSwapper(
            $this->pdo,
            $this->identifiers,
            $this->introspection,
        );
    }

    public function begin(Definition $definition, Context $context): Stage
    {
        return $this->stageBootstrap->begin($definition, $context);
    }

    /**
     * @param iterable<RowContext> $rows
     *
     * @return list<Failure>
     */
    public function ingest(Stage $stage, iterable $rows, MetricsRecorder $metrics): array
    {
        $schema = $stage->definition->schema();

        /** @var array<string, StagingInsertBuffer> $buffers */
        $buffers = [];

        /** @var list<Failure> $failures */
        $failures = [];

        foreach ($rows as $rowContext) {
            $dataset = $stage->definition->map($rowContext->data(), $stage->context);

            foreach ($dataset->mutations() as $mutation) {
                $metrics->recordMutation($mutation->dataset);

                $mutationFailures = $this->stagingIngestor->accumulateRow(
                    $buffers,
                    $schema,
                    $stage->stagingTable($mutation->dataset),
                    $mutation->dataset,
                    $mutation->data,
                    $rowContext,
                    $metrics,
                );

                array_push($failures, ...$mutationFailures);
            }
        }

        foreach ($buffers as $table => $buffer) {
            array_push($failures, ...$this->stagingIngestor->flushBuffer($table, $buffer, $metrics));
        }

        return $failures;
    }

    public function release(Stage $stage): void
    {
        $schema = $stage->definition->schema();

        try {
            $this->pdo->beginTransaction();

            foreach ($schema->datasets() as $datasetName => $_datasetConfig) {
                $this->productionSwapper->replaceFromStaging(
                    $datasetName,
                    $stage->stagingTable($datasetName),
                );
            }

            $this->pdo->commit();
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        } finally {
            $this->stageBootstrap->drop($stage);
        }
    }

    public function rollback(Stage $stage): void
    {
        $this->stageBootstrap->drop($stage);
    }
}
