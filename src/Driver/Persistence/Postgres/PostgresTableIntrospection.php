<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use PDO;
use PDOException;

final class PostgresTableIntrospection
{
    /** @var array<string, list<string>> */
    private array $columnsCache = [];

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
        if (isset($this->columnsCache[$table])) {
            return $this->columnsCache[$table];
        }

        $statement = $this->pdo->query(sprintf(
            'SELECT column_name FROM information_schema.columns WHERE table_name = %s ORDER BY ordinal_position',
            $this->pdo->quote($this->identifiers->basename($table)),
        ));

        if ($statement === false) {
            throw new PDOException(sprintf('Unable to resolve columns for table "%s".', $table));
        }

        /** @var list<string> $columns */
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $this->columnsCache[$table] = $columns;
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
