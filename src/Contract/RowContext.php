<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface RowContext
{
    public function line(): int;

    /**
     * @return array<string, mixed>
     */
    public function data(): array;
}
