<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Context;

use Ivanfuhr\Ingestor\Contract\RowContext;

final readonly class ArrayRowContext implements RowContext
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private int $line,
        private array $data,
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }

    public function data(): array
    {
        return $this->data;
    }
}
