<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Metrics;

use Ivanfuhr\Ingestor\Contract\DatasetMetrics;

final readonly class DatasetMetricsSnapshot implements DatasetMetrics
{
    public function __construct(
        private string $name,
        private int $mutations,
        private int $persisted,
        private int $failures,
    ) {
    }

    public function name(): string
    {
        return $this->name;
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
