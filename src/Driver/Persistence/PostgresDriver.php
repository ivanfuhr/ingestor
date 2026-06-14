<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Conflict\ConflictStrategy;
use Ivanfuhr\Ingestor\Conflict\ConflictType;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use Ivanfuhr\Ingestor\Stage\Stage;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final readonly class PostgresDriver implements PersistenceDriver
{
    private const int DEFAULT_CHUNK_SIZE = 500;

    public function __construct(
        private PDO $pdo,
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1.');
        }
    }

    public function begin(Definition $definition): Stage
    {
        $stageId = $this->generateStageId();
        $schema = $definition->schema();
        $stagingTables = [];

        foreach ($schema->datasets() as $datasetName => $datasetConfig) {
            $stagingTable = $this->stagingTableName($stageId, $datasetName);
            $stagingTables[$datasetName] = $stagingTable;

            if ($datasetConfig->stageStrategy instanceof PrefilledStage) {
                $sql = sprintf(
                    'CREATE UNLOGGED TABLE %s AS TABLE %s',
                    $this->quoteIdentifier($stagingTable),
                    $this->quoteIdentifier($datasetName),
                );
            } elseif ($datasetConfig->stageStrategy instanceof EmptyStage) {
                $sql = sprintf(
                    'CREATE UNLOGGED TABLE %s (LIKE %s INCLUDING ALL)',
                    $this->quoteIdentifier($stagingTable),
                    $this->quoteIdentifier($datasetName),
                );
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported stage strategy for dataset "%s".',
                    $datasetName,
                ));
            }

            $this->pdo->exec($sql);
        }

        return new Stage($stageId, $definition, $stagingTables);
    }

    /**
     * @param iterable<int, array<string, mixed>> $rows
     */
    public function ingest(Stage $stage, iterable $rows): void
    {
        /** @var array<string, array{columns: list<string>, values: list<mixed>, count: int}> $buffers */
        $buffers = [];

        foreach ($rows as $row) {
            $dataset = $stage->definition->map($row);

            foreach ($dataset->mutations() as $mutation) {
                $this->accumulateStagingRow(
                    $buffers,
                    $stage->stagingTable($mutation->dataset),
                    $mutation->data,
                );
            }
        }

        foreach ($buffers as $table => $buffer) {
            $this->flushStagingBuffer($table, $buffer);
        }
    }

    public function release(Stage $stage): void
    {
        $schema = $stage->definition->schema();

        try {
            $this->pdo->beginTransaction();

            foreach ($schema->datasets() as $datasetName => $datasetConfig) {
                $stagingTable = $stage->stagingTable($datasetName);
                $conflict = $datasetConfig->conflictStrategy;

                if ($conflict === null) {
                    $this->copyStagingToProduction($datasetName, $stagingTable, null);

                    continue;
                }

                $this->copyStagingToProduction($datasetName, $stagingTable, $conflict);
            }

            $this->pdo->commit();
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        } finally {
            $this->dropStagingTables($stage);
        }
    }

    public function rollback(Stage $stage): void
    {
        $this->dropStagingTables($stage);
    }

    /**
     * @param array<string, array{columns: list<string>, values: list<mixed>, count: int}> $buffers
     * @param array<string, mixed> $data
     */
    private function accumulateStagingRow(array &$buffers, string $table, array $data): void
    {
        if (!isset($buffers[$table])) {
            $buffers[$table] = [
                'columns' => array_keys($data),
                'values' => [],
                'count' => 0,
            ];
        }

        $buffer = &$buffers[$table];

        foreach ($buffer['columns'] as $column) {
            $buffer['values'][] = $data[$column] ?? null;
        }

        ++$buffer['count'];

        if ($buffer['count'] === $this->chunkSize) {
            $this->executeChunkedInsert($table, $buffer['columns'], $this->chunkSize, $buffer['values']);
            $buffer['values'] = [];
            $buffer['count'] = 0;
        }
    }

    /**
     * @param array{columns: list<string>, values: list<mixed>, count: int} $buffer
     */
    private function flushStagingBuffer(string $table, array $buffer): void
    {
        if ($buffer['count'] === 0) {
            return;
        }

        $this->executeChunkedInsert($table, $buffer['columns'], $buffer['count'], $buffer['values']);
    }

    /**
     * @param list<string> $columns
     * @param list<mixed> $values
     */
    private function executeChunkedInsert(string $table, array $columns, int $rowCount, array $values): void
    {
        $pdoStatement = $this->prepareChunkedStatement($table, $columns, $rowCount);
        $pdoStatement->execute($values);
    }

    /**
     * @param list<string> $columns
     */
    private function prepareChunkedStatement(string $table, array $columns, int $rowCount): PDOStatement
    {
        $columnCount = count($columns);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
        $placeholders = implode(', ', array_fill(0, $rowCount, $rowPlaceholder));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            $placeholders,
        );

        return $this->pdo->prepare($sql);
    }

    private function copyStagingToProduction(
        string $productionTable,
        string $stagingTable,
        ?ConflictStrategy $conflictStrategy,
    ): void {
        if (!$conflictStrategy instanceof ConflictStrategy) {
            $sql = sprintf(
                'INSERT INTO %s SELECT * FROM %s',
                $this->quoteIdentifier($productionTable),
                $this->quoteIdentifier($stagingTable),
            );
            $this->pdo->exec($sql);

            return;
        }

        $conflictColumn = $this->quoteIdentifier($conflictStrategy->column());
        $columns = $this->resolveColumns($stagingTable);
        $updateAssignments = array_map(
            fn (string $column): string => sprintf(
                '%s = EXCLUDED.%s',
                $this->quoteIdentifier($column),
                $this->quoteIdentifier($column),
            ),
            array_filter($columns, static fn (string $column): bool => $column !== $conflictStrategy->column()),
        );

        $stagingSource = $this->deduplicatedStagingSource($stagingTable, $conflictStrategy->column());

        $sql = match ($conflictStrategy->type()) {
            ConflictType::Update => sprintf(
                'INSERT INTO %s SELECT * FROM %s AS ingestor_staging ON CONFLICT (%s) DO UPDATE SET %s',
                $this->quoteIdentifier($productionTable),
                $stagingSource,
                $conflictColumn,
                implode(', ', $updateAssignments),
            ),
            ConflictType::Ignore => sprintf(
                'INSERT INTO %s SELECT * FROM %s AS ingestor_staging ON CONFLICT (%s) DO NOTHING',
                $this->quoteIdentifier($productionTable),
                $stagingSource,
                $conflictColumn,
            ),
            ConflictType::Replace => sprintf(
                'INSERT INTO %s SELECT * FROM %s AS ingestor_staging ON CONFLICT (%s) DO UPDATE SET %s',
                $this->quoteIdentifier($productionTable),
                $stagingSource,
                $conflictColumn,
                implode(', ', array_map(
                    fn (string $column): string => sprintf(
                        '%s = EXCLUDED.%s',
                        $this->quoteIdentifier($column),
                        $this->quoteIdentifier($column),
                    ),
                    $columns,
                )),
            ),
            ConflictType::Fail => $this->buildFailReleaseSql($productionTable, $stagingTable, $conflictStrategy->column()),
        };

        $this->pdo->exec($sql);
    }

    private function deduplicatedStagingSource(string $stagingTable, string $conflictColumn): string
    {
        $quotedConflictColumn = $this->quoteIdentifier($conflictColumn);

        return sprintf(
            '(SELECT * FROM (SELECT DISTINCT ON (%1$s) * FROM %2$s ORDER BY %1$s, ctid DESC) AS ingestor_deduped)',
            $quotedConflictColumn,
            $this->quoteIdentifier($stagingTable),
        );
    }

    private function buildFailReleaseSql(string $productionTable, string $stagingTable, string $conflictColumn): string
    {
        $conflictCountStatement = $this->pdo->query(sprintf(
            'SELECT COUNT(*) FROM %s s WHERE EXISTS (SELECT 1 FROM %s p WHERE p.%s = s.%s)',
            $this->quoteIdentifier($stagingTable),
            $this->quoteIdentifier($productionTable),
            $this->quoteIdentifier($conflictColumn),
            $this->quoteIdentifier($conflictColumn),
        ));

        if ($conflictCountStatement === false) {
            throw new PDOException('Unable to check for conflicting rows during release.');
        }

        $conflictCount = (int) $conflictCountStatement->fetchColumn();

        if ($conflictCount > 0) {
            throw new PDOException(sprintf(
                'Release failed: %d conflicting row(s) detected on column "%s".',
                $conflictCount,
                $conflictColumn,
            ));
        }

        return sprintf(
            'INSERT INTO %s SELECT * FROM %s',
            $this->quoteIdentifier($productionTable),
            $this->quoteIdentifier($stagingTable),
        );
    }

    /**
     * @return list<string>
     */
    private function resolveColumns(string $table): array
    {
        $statement = $this->pdo->query(sprintf(
            'SELECT column_name FROM information_schema.columns WHERE table_name = %s ORDER BY ordinal_position',
            $this->pdo->quote(basename(str_replace('"', '', $table))),
        ));

        if ($statement === false) {
            throw new PDOException(sprintf('Unable to resolve columns for table "%s".', $table));
        }

        /** @var list<string> $columns */
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $columns;
    }

    private function dropStagingTables(Stage $stage): void
    {
        foreach ($stage->stagingTables as $stagingTable) {
            $this->pdo->exec(sprintf(
                'DROP TABLE IF EXISTS %s',
                $this->quoteIdentifier($stagingTable),
            ));
        }
    }

    private function generateStageId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function stagingTableName(string $stageId, string $dataset): string
    {
        return sprintf('ingestor_stage_%s_%s', $stageId, $dataset);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
