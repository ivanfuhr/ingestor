<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use Ivanfuhr\Ingestor\Conflict\ConflictStrategy;
use Ivanfuhr\Ingestor\Conflict\ConflictType;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\RowContext;
use Ivanfuhr\Ingestor\Driver\Persistence\SqlFailureMode;
use Ivanfuhr\Ingestor\Persistence\Failure as PersistenceFailure;
use Ivanfuhr\Ingestor\Schema\Schema;
use PDO;
use PDOException;
use PDOStatement;

final readonly class PostgresStagingIngestor
{
    public function __construct(
        private PDO $pdo,
        private int $chunkSize,
        private SqlFailureMode $failureMode,
        private PostgresIdentifier $identifiers,
        private PostgresTableIntrospection $introspection,
    ) {
    }

    /**
     * @param array<string, StagingInsertBuffer> $buffers
     * @param array<string, mixed> $data
     */
    public function accumulateRow(
        array &$buffers,
        Schema $schema,
        string $table,
        string $dataset,
        array $data,
        RowContext $rowContext,
    ): void {
        if (!isset($buffers[$table])) {
            $buffers[$table] = new StagingInsertBuffer(
                dataset: $dataset,
                conflict: $schema->datasets()[$dataset]->conflictStrategy ?? null,
                columns: array_keys($data),
            );
        }

        $buffer = $buffers[$table];
        $values = [];

        foreach ($buffer->columns as $column) {
            $values[] = $data[$column] ?? null;
        }

        $buffer->rows[] = [
            'context' => $rowContext,
            'values' => $values,
        ];

        ++$buffer->count;

        if ($buffer->count === $this->chunkSize) {
            $this->insertBuffer($table, $buffer);
            $buffer->rows = [];
            $buffer->count = 0;
        }
    }

    /**
     * @return list<Failure>
     */
    public function flushBuffer(string $table, StagingInsertBuffer $buffer): array
    {
        if ($buffer->count === 0) {
            return [];
        }

        return $this->insertBuffer($table, $buffer);
    }

    /**
     * @return list<Failure>
     */
    private function insertBuffer(string $table, StagingInsertBuffer $buffer): array
    {
        return match ($this->failureMode) {
            SqlFailureMode::Fast => $this->insertRowsFast($table, $buffer),
            SqlFailureMode::Diagnostic => $this->insertRowsDiagnostic($table, $buffer),
        };
    }

    /**
     * @return list<Failure>
     */
    private function insertRowsFast(string $table, StagingInsertBuffer $buffer): array
    {
        try {
            $this->executeChunkedInsert($table, $buffer->columns, $buffer->rows, $buffer->conflict);

            return [];
        } catch (PDOException $exception) {
            return [
                PersistenceFailure::fromException(
                    line: null,
                    dataset: $buffer->dataset,
                    data: null,
                    cause: $exception,
                ),
            ];
        }
    }

    /**
     * @return list<Failure>
     */
    private function insertRowsDiagnostic(string $table, StagingInsertBuffer $buffer): array
    {
        return $this->insertRowsDiagnosticChunk(
            $table,
            $buffer->dataset,
            $buffer->columns,
            $buffer->rows,
            $buffer->conflict,
        );
    }

    /**
     * @param list<string> $columns
     * @param list<array{context: RowContext, values: list<mixed>}> $rows
     *
     * @return list<Failure>
     */
    private function insertRowsDiagnosticChunk(
        string $table,
        string $dataset,
        array $columns,
        array $rows,
        ?ConflictStrategy $conflictStrategy,
    ): array {
        if ($rows === []) {
            return [];
        }

        try {
            $this->executeChunkedInsert($table, $columns, $rows, $conflictStrategy);

            return [];
        } catch (PDOException $exception) {
            if (count($rows) === 1) {
                $row = $rows[0];

                return [
                    PersistenceFailure::fromException(
                        line: $row['context']->line(),
                        dataset: $dataset,
                        data: $row['context']->data(),
                        cause: $exception,
                    ),
                ];
            }

            $midpoint = (int) ceil(count($rows) / 2);

            return [
                ...$this->insertRowsDiagnosticChunk($table, $dataset, $columns, array_slice($rows, 0, $midpoint), $conflictStrategy),
                ...$this->insertRowsDiagnosticChunk($table, $dataset, $columns, array_slice($rows, $midpoint), $conflictStrategy),
            ];
        }
    }

    /**
     * @param list<string> $columns
     * @param list<array{context: RowContext, values: list<mixed>}> $rows
     */
    private function executeChunkedInsert(
        string $table,
        array $columns,
        array $rows,
        ?ConflictStrategy $conflictStrategy,
    ): void {
        $values = [];

        foreach ($rows as $row) {
            array_push($values, ...$row['values']);
        }

        $pdoStatement = $this->prepareChunkedStatement($table, $columns, count($rows), $conflictStrategy);
        $pdoStatement->execute($values);
    }

    /**
     * @param list<string> $columns
     */
    private function prepareChunkedStatement(
        string $table,
        array $columns,
        int $rowCount,
        ?ConflictStrategy $conflictStrategy,
    ): PDOStatement {
        $columnCount = count($columns);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
        $placeholders = implode(', ', array_fill(0, $rowCount, $rowPlaceholder));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->identifiers->quote($table),
            implode(', ', array_map($this->identifiers->quote(...), $columns)),
            $placeholders,
        );

        if (!$conflictStrategy instanceof ConflictStrategy || $conflictStrategy->type() === ConflictType::Fail) {
            return $this->pdo->prepare($sql);
        }

        $conflictColumn = $this->identifiers->quote($conflictStrategy->column());
        $tableColumns = $this->introspection->columns($table);

        $sql .= match ($conflictStrategy->type()) {
            ConflictType::Ignore => sprintf(' ON CONFLICT (%s) DO NOTHING', $conflictColumn),
            ConflictType::Update => sprintf(
                ' ON CONFLICT (%s) DO UPDATE SET %s',
                $conflictColumn,
                implode(', ', array_map(
                    fn (string $column): string => sprintf(
                        '%s = EXCLUDED.%s',
                        $this->identifiers->quote($column),
                        $this->identifiers->quote($column),
                    ),
                    array_filter($tableColumns, static fn (string $column): bool => $column !== $conflictStrategy->column()),
                )),
            ),
            ConflictType::Replace => sprintf(
                ' ON CONFLICT (%s) DO UPDATE SET %s',
                $conflictColumn,
                implode(', ', array_map(
                    fn (string $column): string => sprintf(
                        '%s = EXCLUDED.%s',
                        $this->identifiers->quote($column),
                        $this->identifiers->quote($column),
                    ),
                    $tableColumns,
                )),
            ),
        };

        return $this->pdo->prepare($sql);
    }
}
