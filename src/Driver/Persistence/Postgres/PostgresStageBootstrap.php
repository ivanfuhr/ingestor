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
        private PostgresTableIntrospection $introspection,
    ) {
    }

    public function begin(Definition $definition, Context $context): Stage
    {
        $stageId = $this->identifiers->generateStageId();
        $schema = Schema::resolve($definition->schema());
        $stagingTables = [];

        foreach ($schema->datasets() as $datasetName => $datasetConfig) {
            $stagingTable = $this->identifiers->stagingTableName($stageId, $datasetName);
            $stagingTables[$datasetName] = $stagingTable;

            if ($datasetConfig->stageStrategy instanceof PrefilledStage) {
                $quotedStagingTable = $this->identifiers->quote($stagingTable);
                $quotedDataset = $this->identifiers->quote($datasetName);

                $this->pdo->exec(sprintf(
                    'CREATE UNLOGGED TABLE %s (LIKE %s INCLUDING ALL)',
                    $quotedStagingTable,
                    $quotedDataset,
                ));
                $this->pdo->exec(sprintf(
                    'INSERT INTO %s%s SELECT * FROM %s',
                    $quotedStagingTable,
                    $this->introspection->insertOverridingSystemValueClause($stagingTable),
                    $quotedDataset,
                ));
            } elseif ($datasetConfig->stageStrategy instanceof EmptyStage) {
                $sql = sprintf(
                    'CREATE UNLOGGED TABLE %s (LIKE %s INCLUDING ALL)',
                    $this->identifiers->quote($stagingTable),
                    $this->identifiers->quote($datasetName),
                );

                $this->pdo->exec($sql);
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported stage strategy for dataset "%s".',
                    $datasetName,
                ));
            }
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
