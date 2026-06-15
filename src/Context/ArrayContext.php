<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Context;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Contract\Context;

final class ArrayContext implements Context
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $lookupOrDefault = null, mixed $default = null): mixed
    {
        $args = func_num_args();

        if (!$this->has($key)) {
            if ($args < 2) {
                throw new InvalidArgumentException(sprintf('Context key "%s" is not set.', $key));
            }

            if ($args >= 3) {
                return $default;
            }

            return $lookupOrDefault;
        }

        $value = $this->data[$key];

        if ($this->isNestedLookup($lookupOrDefault, $args, $value)) {
            if (!is_array($value)) {
                throw new InvalidArgumentException(sprintf('Context key "%s" is not a lookup map.', $key));
            }

            if ($lookupOrDefault === null) {
                return $args >= 3 ? $default : null;
            }

            if ($args >= 3) {
                return $value[$lookupOrDefault] ?? $default;
            }

            return $value[$lookupOrDefault] ?? null;
        }

        return $value;
    }

    public function has(string $key, string|int|null $lookupKey = null): bool
    {
        if (func_num_args() < 2) {
            return array_key_exists($key, $this->data);
        }

        if (!array_key_exists($key, $this->data)) {
            return false;
        }

        $value = $this->data[$key];

        if (!is_array($value)) {
            return false;
        }

        if ($lookupKey === null) {
            return false;
        }

        return array_key_exists($lookupKey, $value);
    }

    private function isNestedLookup(mixed $lookupOrDefault, int $args, mixed $value): bool
    {
        if ($args >= 3) {
            return true;
        }

        if ($args < 2) {
            return false;
        }

        if (is_string($lookupOrDefault) || is_int($lookupOrDefault)) {
            return true;
        }

        return $lookupOrDefault === null && is_array($value);
    }
}
