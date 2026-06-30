<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use JsonException;

final class PostgresBindValueNormalizer
{
    public function __construct(
        private readonly PostgresTableIntrospection $introspection,
    ) {
    }

    /**
     * @param list<string> $columns
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    public function normalize(string $table, array $columns, array $values): array
    {
        if ($columns === []) {
            return $values;
        }

        $columnTypes = $this->introspection->columnTypes($table);
        $columnCount = count($columns);
        $normalized = [];

        foreach ($values as $index => $value) {
            $column = $columns[$index % $columnCount];
            $type = $columnTypes[$column] ?? null;

            $normalized[] = $this->normalizeValue($value, $type);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, ?string $type): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($type !== null && in_array($type, ['json', 'jsonb'], true)) {
            return $this->encodeJsonValue($value);
        }

        return $value;
    }

    /**
     * @throws JsonException
     */
    private function encodeJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            /** @var array<mixed> $encoded */
            $encoded = $value->toArray();

            return json_encode($encoded, JSON_THROW_ON_ERROR);
        }

        return $value;
    }
}
