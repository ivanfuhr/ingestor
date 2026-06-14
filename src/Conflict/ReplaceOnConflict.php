<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Conflict;

final readonly class ReplaceOnConflict implements ConflictStrategy
{
    /**
     * @param non-empty-list<string> $columns
     */
    private function __construct(
        private array $columns,
    ) {
    }

    public static function by(string ...$columns): self
    {
        return new self(ConflictColumns::from(...$columns));
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function type(): ConflictType
    {
        return ConflictType::Replace;
    }
}
