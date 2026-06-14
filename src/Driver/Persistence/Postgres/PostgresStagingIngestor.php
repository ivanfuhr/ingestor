<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use Ivanfuhr\Ingestor\Conflict\ConflictStrategy;
use Ivanfuhr\Ingestor\Conflict\ConflictType;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\RowContext;
use Ivanfuhr\Ingestor\Driver\Persistence\SqlFailureMode;
use Ivanfuhr\Ingestor\Metrics\MetricsRecorder;
use Ivanfuhr\Ingestor\Persistence\Failure as PersistenceFailure;
use Ivanfuhr\Ingestor\Schema\Schema;
use PDO;
use PDOException;
use PDOStatement;

final class PostgresStagingIngestor
{
    private const int DIAGNOSTIC_LINEAR_SCAN_THRESHOLD = 1_000;

    /** @var array<string, string> */
    private array $conflictClauseCache = [];

    /** @var array<string, PDOStatement> */
    private array $preparedStatementCache = [];

    /** @var array<string, int> */
    private array $tableRowCountCache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $chunkSize,
        private readonly SqlFailureMode $failureMode,
        private readonly PostgresIdentifier $identifiers,
        private readonly PostgresTableIntrospection $introspection,
    ) {
    }

    /**
     * @param array<string, StagingInsertBuffer> $buffers
     * @param array<string, mixed> $data
     *
     * @return list<Failure>
     */
    public function accumulateRow(
        array &$buffers,
        Schema $schema,
        string $table,
        string $dataset,
        array $data,
        RowContext $rowContext,
        MetricsRecorder $metrics,
    ): array {
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
            $failures = $this->insertBuffer($table, $buffer, $metrics);
            $buffer->rows = [];
            $buffer->count = 0;

            return $failures;
        }

        return [];
    }

    /**
     * @return list<Failure>
     */
    public function flushBuffer(string $table, StagingInsertBuffer $buffer, MetricsRecorder $metrics): array
    {
        if ($buffer->count === 0) {
            return [];
        }

        return $this->insertBuffer($table, $buffer, $metrics);
    }

    /**
     * @return list<Failure>
     */
    private function insertBuffer(string $table, StagingInsertBuffer $buffer, MetricsRecorder $metrics): array
    {
        return match ($this->failureMode) {
            SqlFailureMode::Fast => $this->insertRowsFast($table, $buffer, $metrics),
            SqlFailureMode::Diagnostic => $this->insertRowsDiagnostic($table, $buffer, $metrics),
        };
    }

    /**
     * @return list<Failure>
     */
    private function insertRowsFast(string $table, StagingInsertBuffer $buffer, MetricsRecorder $metrics): array
    {
        $rowCount = count($buffer->rows);

        try {
            $this->executeChunkedInsert($table, $buffer->columns, $buffer->rows, $buffer->conflict);
            $metrics->recordPersisted($buffer->dataset, $rowCount);

            return [];
        } catch (PDOException $exception) {
            $metrics->recordDatasetFailure($buffer->dataset, $rowCount);

            for ($i = 0; $i < $rowCount; ++$i) {
                $metrics->recordRowFailed();
            }

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
    private function insertRowsDiagnostic(string $table, StagingInsertBuffer $buffer, MetricsRecorder $metrics): array
    {
        return $this->insertRowsDiagnosticChunk(
            $table,
            $buffer->dataset,
            $buffer->columns,
            $buffer->rows,
            $buffer->conflict,
            $metrics,
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
        MetricsRecorder $metrics,
        int $offset = 0,
        ?int $length = null,
    ): array {
        $length ??= count($rows);

        if ($length === 0) {
            return [];
        }

        try {
            $this->executeChunkedInsert($table, $columns, $rows, $conflictStrategy, $offset, $length);
            $metrics->recordPersisted($dataset, $length);

            return [];
        } catch (PDOException $exception) {
            if ($length === 1) {
                $row = $rows[$offset];
                $metrics->recordDatasetFailure($dataset);

                return [
                    PersistenceFailure::fromException(
                        line: $row['context']->line(),
                        dataset: $dataset,
                        data: $row['context']->data(),
                        cause: $exception,
                    ),
                ];
            }

            if ($this->shouldUseLinearDiagnostic($table, $conflictStrategy)) {
                return $this->insertRowsDiagnosticLinear(
                    $table,
                    $dataset,
                    $columns,
                    $rows,
                    $conflictStrategy,
                    $metrics,
                    $offset,
                    $length,
                );
            }

            $midpoint = (int) ceil($length / 2);

            return [
                ...$this->insertRowsDiagnosticChunk(
                    $table,
                    $dataset,
                    $columns,
                    $rows,
                    $conflictStrategy,
                    $metrics,
                    $offset,
                    $midpoint,
                ),
                ...$this->insertRowsDiagnosticChunk(
                    $table,
                    $dataset,
                    $columns,
                    $rows,
                    $conflictStrategy,
                    $metrics,
                    $offset + $midpoint,
                    $length - $midpoint,
                ),
            ];
        }
    }

    /**
     * @param list<string> $columns
     * @param list<array{context: RowContext, values: list<mixed>}> $rows
     *
     * @return list<Failure>
     */
    private function insertRowsDiagnosticLinear(
        string $table,
        string $dataset,
        array $columns,
        array $rows,
        ?ConflictStrategy $conflictStrategy,
        MetricsRecorder $metrics,
        int $offset,
        int $length,
    ): array {
        /** @var list<Failure> $failures */
        $failures = [];

        for ($index = $offset; $index < $offset + $length; ++$index) {
            $row = $rows[$index];

            try {
                $this->executeChunkedInsert($table, $columns, $rows, $conflictStrategy, $index, 1);
                $metrics->recordPersisted($dataset, 1);
            } catch (PDOException $exception) {
                $metrics->recordDatasetFailure($dataset);

                $failures[] = PersistenceFailure::fromException(
                    line: $row['context']->line(),
                    dataset: $dataset,
                    data: $row['context']->data(),
                    cause: $exception,
                );
            }
        }

        return $failures;
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
        int $offset = 0,
        ?int $length = null,
    ): void {
        $length ??= count($rows);
        $values = [];

        for ($index = $offset; $index < $offset + $length; ++$index) {
            array_push($values, ...$rows[$index]['values']);
        }

        $pdoStatement = $this->prepareChunkedStatement($table, $columns, $length, $conflictStrategy);
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
        $sql = $this->buildInsertSql($table, $columns, $rowCount, $conflictStrategy);

        if (isset($this->preparedStatementCache[$sql])) {
            return $this->preparedStatementCache[$sql];
        }

        $statement = $this->pdo->prepare($sql);

        return $this->preparedStatementCache[$sql] = $statement;
    }

    /**
     * @param list<string> $columns
     */
    private function buildInsertSql(
        string $table,
        array $columns,
        int $rowCount,
        ?ConflictStrategy $conflictStrategy,
    ): string {
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
            return $sql;
        }

        return $sql . $this->conflictClause($table, $conflictStrategy);
    }

    private function conflictClause(string $table, ConflictStrategy $conflictStrategy): string
    {
        $cacheKey = $table . "\0" . $conflictStrategy->type()->name . "\0" . implode("\0", $conflictStrategy->columns());

        if (isset($this->conflictClauseCache[$cacheKey])) {
            return $this->conflictClauseCache[$cacheKey];
        }

        $conflictColumns = implode(', ', array_map(
            $this->identifiers->quote(...),
            $conflictStrategy->columns(),
        ));
        $tableColumns = $this->introspection->columns($table);
        $conflictColumnSet = array_fill_keys($conflictStrategy->columns(), true);

        $clause = match ($conflictStrategy->type()) {
            ConflictType::Ignore => sprintf(' ON CONFLICT (%s) DO NOTHING', $conflictColumns),
            ConflictType::Update => sprintf(
                ' ON CONFLICT (%s) DO UPDATE SET %s',
                $conflictColumns,
                implode(', ', array_map(
                    fn (string $column): string => sprintf(
                        '%s = EXCLUDED.%s',
                        $this->identifiers->quote($column),
                        $this->identifiers->quote($column),
                    ),
                    array_filter($tableColumns, static fn (string $column): bool => !isset($conflictColumnSet[$column])),
                )),
            ),
            ConflictType::Replace => sprintf(
                ' ON CONFLICT (%s) DO UPDATE SET %s',
                $conflictColumns,
                implode(', ', array_map(
                    fn (string $column): string => sprintf(
                        '%s = EXCLUDED.%s',
                        $this->identifiers->quote($column),
                        $this->identifiers->quote($column),
                    ),
                    $tableColumns,
                )),
            ),
            ConflictType::Fail => '',
        };

        return $this->conflictClauseCache[$cacheKey] = $clause;
    }

    private function shouldUseLinearDiagnostic(string $table, ?ConflictStrategy $conflictStrategy): bool
    {
        if (!$conflictStrategy instanceof ConflictStrategy) {
            return false;
        }

        if (!match ($conflictStrategy->type()) {
            ConflictType::Update, ConflictType::Replace => true,
            default => false,
        }) {
            return false;
        }

        return $this->tableRowCount($table) > self::DIAGNOSTIC_LINEAR_SCAN_THRESHOLD;
    }

    private function tableRowCount(string $table): int
    {
        if (isset($this->tableRowCountCache[$table])) {
            return $this->tableRowCountCache[$table];
        }

        $statement = $this->pdo->query(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->identifiers->quote($table),
        ));

        if ($statement === false) {
            return 0;
        }

        return $this->tableRowCountCache[$table] = (int) $statement->fetchColumn();
    }
}
