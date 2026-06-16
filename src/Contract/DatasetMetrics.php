<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Conflict\ConflictType;

interface DatasetMetrics
{
    public function name(): string;

    public function stageStrategy(): string;

    public function onConflict(): ?ConflictType;

    /**
     * @return list<string>
     */
    public function onConflictColumns(): array;

    public function mutations(): int;

    public function persisted(): int;

    public function failures(): int;
}
