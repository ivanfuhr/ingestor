<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Metrics;

use DateTimeImmutable;
use Ivanfuhr\Ingestor\Conflict\ConflictType;
use Ivanfuhr\Ingestor\Schema\DatasetConfig;

final class MetricsRecorder
{
    private readonly DateTimeImmutable $startedAt;

    private ?DateTimeImmutable $finishedAt = null;

    private int $rows = 0;

    private int $failedRows = 0;

    private int $mutations = 0;

    /** @var array<string, array{mutations: int, persisted: int, failures: int, stageStrategy: string, onConflict: ?ConflictType, onConflictColumns: list<string>}> */
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

    /**
     * @param array<string, DatasetConfig> $configs
     */
    public function registerDatasets(array $configs): void
    {
        foreach ($configs as $name => $config) {
            $existing = $this->datasets[$name] ?? null;

            $this->datasets[$name] = [
                'mutations' => $existing['mutations'] ?? 0,
                'persisted' => $existing['persisted'] ?? 0,
                'failures' => $existing['failures'] ?? 0,
                'stageStrategy' => $config->stageStrategy::class,
                'onConflict' => $config->conflictStrategy?->type(),
                'onConflictColumns' => $config->conflictStrategy?->columns() ?? [],
            ];
        }
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
                stageStrategy: $counts['stageStrategy'],
                onConflict: $counts['onConflict'],
                onConflictColumns: $counts['onConflictColumns'],
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
     * @return array{mutations: int, persisted: int, failures: int, stageStrategy: string, onConflict: ?ConflictType, onConflictColumns: list<string>}
     */
    private function &dataset(string $name): array
    {
        if (!isset($this->datasets[$name])) {
            $this->datasets[$name] = [
                'mutations' => 0,
                'persisted' => 0,
                'failures' => 0,
                'stageStrategy' => '',
                'onConflict' => null,
                'onConflictColumns' => [],
            ];
        }

        return $this->datasets[$name];
    }
}
