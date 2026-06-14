<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface DatasetMetrics
{
    public function name(): string;

    public function mutations(): int;

    public function persisted(): int;

    public function failures(): int;
}
