<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use DateTimeInterface;
use Ivanfuhr\Ingestor\Duration;

interface Metrics
{
    public function startedAt(): DateTimeInterface;

    public function finishedAt(): ?DateTimeInterface;

    public function duration(): Duration;

    public function rows(): int;

    public function importedRows(): int;

    public function failedRows(): int;

    public function mutations(): int;

    /**
     * @return iterable<DatasetMetrics>
     */
    public function datasets(): iterable;
}
