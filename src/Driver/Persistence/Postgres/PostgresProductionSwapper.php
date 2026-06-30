<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use PDO;
use PDOException;

final readonly class PostgresProductionSwapper
{
    public function __construct(
        private PDO $pdo,
        private PostgresIdentifier $identifiers,
        private PostgresTableIntrospection $introspection,
    ) {
    }

    /**
     * Replaces production data with staging contents in-place.
     *
     * The production relation (OID) is preserved so dependent objects
     * (foreign keys, views, triggers, grants, indexes, constraints) remain attached.
     */
    public function replaceFromStaging(string $productionTable, string $stagingTable): void
    {
        $quotedProduction = $this->identifiers->quote($productionTable);
        $quotedStaging = $this->identifiers->quote($stagingTable);

        $this->pdo->exec(sprintf('LOCK TABLE %s IN ACCESS EXCLUSIVE MODE', $quotedProduction));

        if ($this->tryTruncateTable($quotedProduction)) {
            $this->copyStagingRows($productionTable, $quotedProduction, $quotedStaging);
            $this->introspection->synchronizeSequences($productionTable);

            return;
        }

        if ($this->tryClearTable($quotedProduction, replicaRole: true)) {
            $this->copyStagingRows($productionTable, $quotedProduction, $quotedStaging);
            $this->introspection->synchronizeSequences($productionTable);

            return;
        }

        if ($this->tryClearTable($quotedProduction, replicaRole: false)) {
            $this->copyStagingRows($productionTable, $quotedProduction, $quotedStaging);
            $this->introspection->synchronizeSequences($productionTable);

            return;
        }

        throw new PDOException(sprintf(
            'Unable to replace contents of table "%s". The table may be referenced by foreign keys from other tables.',
            $productionTable,
        ));
    }

    private function copyStagingRows(string $productionTable, string $quotedProduction, string $quotedStaging): void
    {
        $this->pdo->exec(sprintf(
            'INSERT INTO %s%s SELECT * FROM %s',
            $quotedProduction,
            $this->introspection->insertOverridingSystemValueClause($productionTable),
            $quotedStaging,
        ));
    }

    private function tryTruncateTable(string $quotedProduction): bool
    {
        return $this->attempt(function () use ($quotedProduction): void {
            $this->pdo->exec(sprintf('TRUNCATE %s', $quotedProduction));
        });
    }

    private function tryClearTable(string $quotedProduction, bool $replicaRole): bool
    {
        return $this->attempt(function () use ($quotedProduction, $replicaRole): void {
            if ($replicaRole) {
                $this->pdo->exec("SET session_replication_role = 'replica'");
            }

            try {
                $this->pdo->exec(sprintf('DELETE FROM %s', $quotedProduction));
            } finally {
                if ($replicaRole) {
                    $this->pdo->exec("SET session_replication_role = 'origin'");
                }
            }
        });
    }

    /**
     * @param callable(): void $operation
     */
    private function attempt(callable $operation): bool
    {
        $savepoint = 'ingestor_' . bin2hex(random_bytes(4));
        $this->pdo->exec(sprintf('SAVEPOINT %s', $savepoint));

        try {
            $operation();
            $this->pdo->exec(sprintf('RELEASE SAVEPOINT %s', $savepoint));

            return true;
        } catch (PDOException) {
            $this->pdo->exec(sprintf('ROLLBACK TO SAVEPOINT %s', $savepoint));
            $this->pdo->exec(sprintf('RELEASE SAVEPOINT %s', $savepoint));

            return false;
        }
    }
}
