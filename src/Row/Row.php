<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Row;

use Ivanfuhr\Ingestor\Contract\RowContext;

final readonly class Row
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private int $line,
        private array $data,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function make(int $line, array $data): self
    {
        return new self($line, $data);
    }

    public static function fromContext(RowContext $context): self
    {
        return new self($context->line(), $context->data());
    }

    public function line(): int
    {
        return $this->line;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function missing(string $key): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return true;
        }

        $value = $this->data[$key];

        return $value === null || $value === '';
    }

    public function filled(string $key): bool
    {
        return !$this->missing($key);
    }

    public function string(string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $this->data)) {
            return $default;
        }

        $value = $this->data[$key];

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        if (!array_key_exists($key, $this->data)) {
            return $default;
        }

        $value = $this->data[$key];

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        if (!array_key_exists($key, $this->data)) {
            return $default;
        }

        $value = $this->data[$key];

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                $result[$key] = $this->data[$key];
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
