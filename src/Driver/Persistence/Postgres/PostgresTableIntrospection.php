<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use PDO;
use PDOException;

final class PostgresTableIntrospection
{
    /** @var array<string, array<string, string>> */
    private array $columnTypesCache = [];

    /** @var array<string, list<string>> */
    private array $identityColumnsCache = [];

    /** @var array<string, list<string>> */
    private array $generatedAlwaysIdentityColumnsCache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PostgresIdentifier $identifiers,
    ) {
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        return array_keys($this->columnTypes($table));
    }

    /**
     * @return array<string, string> column name => PostgreSQL udt_name
     */
    public function columnTypes(string $table): array
    {
        if (isset($this->columnTypesCache[$table])) {
            return $this->columnTypesCache[$table];
        }

        $statement = $this->pdo->query(sprintf(
            <<<'SQL'
            SELECT column_name, udt_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = %s
            ORDER BY ordinal_position
            SQL,
            $this->pdo->quote($this->identifiers->basename($table)),
        ));

        if ($statement === false) {
            throw new PDOException(sprintf('Unable to resolve columns for table "%s".', $table));
        }

        /** @var list<array{column_name: string, udt_name: string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<string, string> $types */
        $types = [];

        foreach ($rows as $row) {
            $types[$row['column_name']] = $row['udt_name'];
        }

        return $this->columnTypesCache[$table] = $types;
    }

    /**
     * @return list<string>
     */
    public function identityColumns(string $table): array
    {
        if (isset($this->identityColumnsCache[$table])) {
            return $this->identityColumnsCache[$table];
        }

        $statement = $this->pdo->query(sprintf(
            <<<'SQL'
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = %s
              AND is_identity = 'YES'
            ORDER BY ordinal_position
            SQL,
            $this->pdo->quote($this->identifiers->basename($table)),
        ));

        if ($statement === false) {
            throw new PDOException(sprintf('Unable to resolve identity columns for table "%s".', $table));
        }

        /** @var list<string> $columns */
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $this->identityColumnsCache[$table] = $columns;
    }

    /**
     * @return list<string>
     */
    public function generatedAlwaysIdentityColumns(string $table): array
    {
        if (isset($this->generatedAlwaysIdentityColumnsCache[$table])) {
            return $this->generatedAlwaysIdentityColumnsCache[$table];
        }

        $statement = $this->pdo->query(sprintf(
            <<<'SQL'
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = %s
              AND is_identity = 'YES'
              AND identity_generation = 'ALWAYS'
            ORDER BY ordinal_position
            SQL,
            $this->pdo->quote($this->identifiers->basename($table)),
        ));

        if ($statement === false) {
            throw new PDOException(sprintf('Unable to resolve identity columns for table "%s".', $table));
        }

        /** @var list<string> $columns */
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $this->generatedAlwaysIdentityColumnsCache[$table] = $columns;
    }

    /**
     * @param list<string> $columns
     */
    public function requiresOverridingSystemValue(string $table, array $columns): bool
    {
        $identityColumns = array_fill_keys($this->generatedAlwaysIdentityColumns($table), true);

        foreach ($columns as $column) {
            if (isset($identityColumns[$column])) {
                return true;
            }
        }

        return false;
    }

    public function insertOverridingSystemValueClause(string $table): string
    {
        return $this->generatedAlwaysIdentityColumns($table) !== []
            ? ' OVERRIDING SYSTEM VALUE'
            : '';
    }

    /**
     * Omits blank identity values so PostgreSQL can auto-generate them.
     *
     * Mirrors Laravel/Eloquent behavior where `id` is not sent when absent.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function sanitizeInsertData(string $table, array $data): array
    {
        foreach ($this->identityColumns($table) as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            if ($this->isBlankInsertValue($data[$column])) {
                unset($data[$column]);
            }
        }

        return $data;
    }

    private function isBlankInsertValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * @return list<string> schema-qualified table names referencing the given table
     */
    public function referencingTables(string $table): array
    {
        $tableName = $this->identifiers->basename($table);

        $statement = $this->pdo->query(sprintf(
            <<<'SQL'
            SELECT DISTINCT format('%I.%I', referencing_ns.nspname, referencing.relname) AS referencing_table
            FROM pg_constraint AS constraint
            INNER JOIN pg_class AS referenced ON referenced.oid = constraint.confrelid
            INNER JOIN pg_namespace AS referenced_ns ON referenced_ns.oid = referenced.relnamespace
            INNER JOIN pg_class AS referencing ON referencing.oid = constraint.conrelid
            INNER JOIN pg_namespace AS referencing_ns ON referencing_ns.oid = referencing.relnamespace
            WHERE constraint.contype = 'f'
              AND referenced_ns.nspname = 'public'
              AND referenced.relname = %s
            ORDER BY referencing_table
            SQL,
            $this->pdo->quote($tableName),
        ));

        if ($statement === false) {
            throw new PDOException(sprintf('Unable to resolve foreign key references for table "%s".', $table));
        }

        /** @var list<string> $tables */
        $tables = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $tables;
    }

    public function synchronizeSequences(string $productionTable): void
    {
        $tableName = $this->identifiers->basename($productionTable);

        $statement = $this->pdo->query(sprintf(
            <<<'SQL'
            SELECT a.attname AS column_name
            FROM pg_class AS c
            INNER JOIN pg_namespace AS n ON n.oid = c.relnamespace
            INNER JOIN pg_attribute AS a ON a.attrelid = c.oid
            WHERE n.nspname = 'public'
              AND c.relname = %s
              AND a.attnum > 0
              AND NOT a.attisdropped
              AND pg_get_serial_sequence(format('%%I.%%I', n.nspname, c.relname), a.attname) IS NOT NULL
            SQL,
            $this->pdo->quote($tableName),
        ));

        if ($statement === false) {
            return;
        }

        $quotedProduction = $this->identifiers->quote($productionTable);
        $qualifiedTable = $this->pdo->quote('public.' . $tableName);

        /** @var list<string> $columns */
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

        foreach ($columns as $column) {
            $quotedColumn = $this->identifiers->quote($column);

            $this->pdo->exec(sprintf(
                'SELECT setval(pg_get_serial_sequence(%s, %s), COALESCE((SELECT MAX(%s) FROM %s), 1), (SELECT COUNT(*) > 0 FROM %s))',
                $qualifiedTable,
                $this->pdo->quote($column),
                $quotedColumn,
                $quotedProduction,
                $quotedProduction,
            ));
        }
    }
}
