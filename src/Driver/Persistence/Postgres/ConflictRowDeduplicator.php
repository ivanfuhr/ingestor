<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use Ivanfuhr\Ingestor\Conflict\DuplicateInBatch;
use Ivanfuhr\Ingestor\Conflict\ReplaceOnConflict;
use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\RowContext;
use Ivanfuhr\Ingestor\Persistence\Failure as PersistenceFailure;

final class ConflictRowDeduplicator
{
    /**
     * @return array{rows: list<array{context: RowContext, values: list<mixed>}>, failures: list<Failure>}
     */
    public function resolve(StagingInsertBuffer $buffer): array
    {
        $conflict = $buffer->conflict;

        if (!$conflict instanceof UpdateOnConflict && !$conflict instanceof ReplaceOnConflict) {
            return ['rows' => $buffer->rows, 'failures' => []];
        }

        if ($buffer->rows === []) {
            return ['rows' => [], 'failures' => []];
        }

        $duplicateInBatch = $conflict->duplicateInBatch();
        $indices = $this->conflictColumnIndices($buffer->columns, $conflict->columns());

        return match ($duplicateInBatch) {
            DuplicateInBatch::LastWins => [
                'rows' => $this->keepLastOccurrences($buffer->rows, $indices),
                'failures' => [],
            ],
            DuplicateInBatch::FirstWins => [
                'rows' => $this->keepFirstOccurrences($buffer->rows, $indices),
                'failures' => [],
            ],
            DuplicateInBatch::Fail => $this->failOnDuplicates($buffer->dataset, $buffer->rows, $indices, $conflict->columns()),
        };
    }

    /**
     * @param list<string> $columns
     * @param non-empty-list<string> $conflictColumns
     *
     * @return list<int>
     */
    private function conflictColumnIndices(array $columns, array $conflictColumns): array
    {
        $columnIndex = array_flip($columns);

        return array_map(
            static fn (string $column): int => $columnIndex[$column],
            $conflictColumns,
        );
    }

    /**
     * @param list<array{context: RowContext, values: list<mixed>}> $rows
     * @param list<int> $indices
     *
     * @return list<array{context: RowContext, values: list<mixed>}>
     */
    private function keepLastOccurrences(array $rows, array $indices): array
    {
        $lastIndexByKey = [];

        foreach ($rows as $index => $row) {
            $lastIndexByKey[$this->conflictKey($row['values'], $indices)] = $index;
        }

        $resolved = [];

        foreach ($rows as $index => $row) {
            if ($lastIndexByKey[$this->conflictKey($row['values'], $indices)] === $index) {
                $resolved[] = $row;
            }
        }

        return $resolved;
    }

    /**
     * @param list<array{context: RowContext, values: list<mixed>}> $rows
     * @param list<int> $indices
     *
     * @return list<array{context: RowContext, values: list<mixed>}>
     */
    private function keepFirstOccurrences(array $rows, array $indices): array
    {
        $seen = [];
        $resolved = [];

        foreach ($rows as $row) {
            $key = $this->conflictKey($row['values'], $indices);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $resolved[] = $row;
        }

        return $resolved;
    }

    /**
     * @param list<array{context: RowContext, values: list<mixed>}> $rows
     * @param list<int> $indices
     * @param non-empty-list<string> $conflictColumns
     *
     * @return array{rows: list<array{context: RowContext, values: list<mixed>}>, failures: list<Failure>}
     */
    private function failOnDuplicates(
        string $dataset,
        array $rows,
        array $indices,
        array $conflictColumns,
    ): array {
        /** @var array<string, list<array{context: RowContext, values: list<mixed>}>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $groups[$this->conflictKey($row['values'], $indices)][] = $row;
        }

        $duplicateGroups = array_filter($groups, static fn (array $group): bool => count($group) > 1);

        if ($duplicateGroups === []) {
            return ['rows' => $rows, 'failures' => []];
        }

        /** @var list<Failure> $failures */
        $failures = [];

        foreach ($duplicateGroups as $group) {
            $lines = array_map(
                static fn (array $row): int => $row['context']->line(),
                $group,
            );

            foreach ($group as $row) {
                $failures[] = PersistenceFailure::duplicateConflictKeyInBatch(
                    line: $row['context']->line(),
                    dataset: $dataset,
                    data: $row['context']->data(),
                    conflictColumns: $conflictColumns,
                    lines: $lines,
                );
            }
        }

        return ['rows' => [], 'failures' => $failures];
    }

    /**
     * @param list<mixed> $values
     * @param list<int> $indices
     */
    private function conflictKey(array $values, array $indices): string
    {
        $parts = [];

        foreach ($indices as $index) {
            $parts[] = $values[$index];
        }

        return json_encode($parts, JSON_THROW_ON_ERROR);
    }
}
