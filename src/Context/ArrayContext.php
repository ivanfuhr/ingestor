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

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            if (func_num_args() < 2) {
                throw new InvalidArgumentException(sprintf('Context key "%s" is not set.', $key));
            }

            return $default;
        }

        return $this->data[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
