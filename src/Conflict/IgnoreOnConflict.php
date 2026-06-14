<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Conflict;

final readonly class IgnoreOnConflict implements ConflictStrategy
{
    private function __construct(
        private string $column,
    ) {
    }

    public static function by(string $column): self
    {
        return new self($column);
    }

    public function column(): string
    {
        return $this->column;
    }

    public function type(): ConflictType
    {
        return ConflictType::Ignore;
    }
}
