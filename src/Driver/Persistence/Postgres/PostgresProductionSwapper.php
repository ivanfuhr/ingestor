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

        if ($this->tryClearTableWithForeignKeyTriggersDisabled($productionTable, $quotedProduction)) {
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
            'Unable to replace contents of table "%s". The table is referenced by foreign keys from other tables. '
            . 'The database role must be able to bypass FK checks (superuser, or permission to set session_replication_role). '
            . 'Importing referenced tables in the same job does not remove this requirement.',
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
            $this->pdo->exec(sprintf('LOCK TABLE %s IN ACCESS EXCLUSIVE MODE', $quotedProduction));
            $this->pdo->exec(sprintf('TRUNCATE %s', $quotedProduction));
        });
    }

    private function tryClearTable(string $quotedProduction, bool $replicaRole): bool
    {
        return $this->attempt(function () use ($quotedProduction, $replicaRole): void {
            $this->pdo->exec(sprintf('LOCK TABLE %s IN EXCLUSIVE MODE', $quotedProduction));

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

    private function tryClearTableWithForeignKeyTriggersDisabled(string $productionTable, string $quotedProduction): bool
    {
        $referencingTables = $this->introspection->referencingTables($productionTable);

        if ($referencingTables === [] && !$this->tableHasSelfReferencingForeignKey($productionTable)) {
            return false;
        }

        /** @var list<string> $tablesToDisable */
        $tablesToDisable = array_values(array_unique([$productionTable, ...$referencingTables]));

        return $this->attempt(function () use ($quotedProduction, $tablesToDisable): void {
            foreach ($tablesToDisable as $table) {
                $quoted = $this->identifiers->quoteQualified($table);
                $this->pdo->exec(sprintf('LOCK TABLE %s IN EXCLUSIVE MODE', $quoted));
                $this->pdo->exec(sprintf('ALTER TABLE %s DISABLE TRIGGER ALL', $quoted));
            }

            try {
                $this->pdo->exec(sprintf('DELETE FROM %s', $quotedProduction));
            } finally {
                foreach ($tablesToDisable as $table) {
                    $quoted = $this->identifiers->quoteQualified($table);
                    $this->pdo->exec(sprintf('ALTER TABLE %s ENABLE TRIGGER ALL', $quoted));
                }
            }
        });
    }

    private function tableHasSelfReferencingForeignKey(string $productionTable): bool
    {
        $tableName = $this->identifiers->basename($productionTable);

        $statement = $this->pdo->query(sprintf(
            <<<'SQL'
            SELECT 1
            FROM pg_constraint AS fk
            INNER JOIN pg_class AS referenced ON referenced.oid = fk.confrelid
            INNER JOIN pg_namespace AS referenced_ns ON referenced_ns.oid = referenced.relnamespace
            WHERE fk.contype = 'f'
              AND fk.conrelid = fk.confrelid
              AND referenced_ns.nspname = 'public'
              AND referenced.relname = %s
            LIMIT 1
            SQL,
            $this->pdo->quote($tableName),
        ));

        if ($statement === false) {
            return false;
        }

        return $statement->fetchColumn() !== false;
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
