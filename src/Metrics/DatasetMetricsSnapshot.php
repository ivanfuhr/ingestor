<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Metrics;

use Ivanfuhr\Ingestor\Conflict\ConflictType;
use Ivanfuhr\Ingestor\Contract\DatasetMetrics;

final readonly class DatasetMetricsSnapshot implements DatasetMetrics
{
    /**
     * @param list<string> $onConflictColumns
     */
    public function __construct(
        private string $name,
        private string $stageStrategy,
        private ?ConflictType $onConflict,
        private array $onConflictColumns,
        private int $mutations,
        private int $persisted,
        private int $failures,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function stageStrategy(): string
    {
        return $this->stageStrategy;
    }

    public function onConflict(): ?ConflictType
    {
        return $this->onConflict;
    }

    public function onConflictColumns(): array
    {
        return $this->onConflictColumns;
    }

    public function mutations(): int
    {
        return $this->mutations;
    }

    public function persisted(): int
    {
        return $this->persisted;
    }

    public function failures(): int
    {
        return $this->failures;
    }
}
