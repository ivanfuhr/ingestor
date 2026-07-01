<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Metrics;

use DateTimeInterface;
use Ivanfuhr\Ingestor\Contract\DatasetMetrics;
use Ivanfuhr\Ingestor\Contract\Metrics;
use Ivanfuhr\Ingestor\Duration;

final readonly class ImportMetrics implements Metrics
{
    /**
     * @param list<DatasetMetricsSnapshot> $datasets
     */
    public function __construct(
        private DateTimeInterface $startedAt,
        private ?DateTimeInterface $finishedAt,
        private int $rows,
        private int $importedRows,
        private int $failedRows,
        private int $mutations,
        private array $datasets,
    ) {
    }

    public function startedAt(): DateTimeInterface
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function duration(): Duration
    {
        if (!$this->finishedAt instanceof DateTimeInterface) {
            return new Duration(0);
        }

        return Duration::between($this->startedAt, $this->finishedAt);
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function importedRows(): int
    {
        return $this->importedRows;
    }

    public function failedRows(): int
    {
        return $this->failedRows;
    }

    public function mutations(): int
    {
        return $this->mutations;
    }

    /**
     * @return list<DatasetMetrics>
     */
    public function datasets(): array
    {
        return $this->datasets;
    }
}
