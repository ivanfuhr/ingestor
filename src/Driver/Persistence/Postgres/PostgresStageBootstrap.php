<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use Ivanfuhr\Ingestor\Stage\Stage;
use PDO;

final readonly class PostgresStageBootstrap
{
    public function __construct(
        private PDO $pdo,
        private PostgresIdentifier $identifiers,
    ) {
    }

    public function begin(Definition $definition, Context $context): Stage
    {
        $stageId = $this->identifiers->generateStageId();
        $schema = $definition->schema();
        $stagingTables = [];

        foreach ($schema->datasets() as $datasetName => $datasetConfig) {
            $stagingTable = $this->identifiers->stagingTableName($stageId, $datasetName);
            $stagingTables[$datasetName] = $stagingTable;

            if ($datasetConfig->stageStrategy instanceof PrefilledStage) {
                $sql = sprintf(
                    'CREATE UNLOGGED TABLE %s AS TABLE %s',
                    $this->identifiers->quote($stagingTable),
                    $this->identifiers->quote($datasetName),
                );
            } elseif ($datasetConfig->stageStrategy instanceof EmptyStage) {
                $sql = sprintf(
                    'CREATE UNLOGGED TABLE %s (LIKE %s INCLUDING ALL)',
                    $this->identifiers->quote($stagingTable),
                    $this->identifiers->quote($datasetName),
                );
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported stage strategy for dataset "%s".',
                    $datasetName,
                ));
            }

            $this->pdo->exec($sql);
        }

        return new Stage($stageId, $definition, $stagingTables, $context);
    }

    public function drop(Stage $stage): void
    {
        foreach ($stage->stagingTables as $stagingTable) {
            $this->pdo->exec(sprintf(
                'DROP TABLE IF EXISTS %s',
                $this->identifiers->quote($stagingTable),
            ));
        }
    }
}
