<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface Context
{
    public function put(string $key, mixed $value): void;

    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;
}
