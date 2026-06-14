<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Metrics;

use DateTimeImmutable;

final class MetricsRecorder
{
    private readonly DateTimeImmutable $startedAt;

    private ?DateTimeImmutable $finishedAt = null;

    private int $rows = 0;

    private int $failedRows = 0;

    private int $mutations = 0;

    /** @var array<string, array{mutations: int, persisted: int, failures: int}> */
    private array $datasets = [];

    public function __construct()
    {
        $this->startedAt = new DateTimeImmutable();
    }

    public function finish(): void
    {
        $this->finishedAt = new DateTimeImmutable();
    }

    public function recordRow(): void
    {
        ++$this->rows;
    }

    public function recordRowFailed(): void
    {
        ++$this->failedRows;
    }

    public function recordMutation(string $dataset): void
    {
        ++$this->mutations;
        ++$this->dataset($dataset)['mutations'];
    }

    public function recordPersisted(string $dataset, int $count = 1): void
    {
        $this->dataset($dataset)['persisted'] += $count;
    }

    public function recordDatasetFailure(string $dataset, int $count = 1): void
    {
        $this->dataset($dataset)['failures'] += $count;
    }

    public function snapshot(): ImportMetrics
    {
        $datasets = [];

        foreach ($this->datasets as $name => $counts) {
            $datasets[] = new DatasetMetricsSnapshot(
                name: $name,
                mutations: $counts['mutations'],
                persisted: $counts['persisted'],
                failures: $counts['failures'],
            );
        }

        return new ImportMetrics(
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            rows: $this->rows,
            importedRows: max(0, $this->rows - $this->failedRows),
            failedRows: $this->failedRows,
            mutations: $this->mutations,
            datasets: $datasets,
        );
    }

    /**
     * @return array{mutations: int, persisted: int, failures: int}
     */
    private function &dataset(string $name): array
    {
        if (!isset($this->datasets[$name])) {
            $this->datasets[$name] = ['mutations' => 0, 'persisted' => 0, 'failures' => 0];
        }

        return $this->datasets[$name];
    }
}
