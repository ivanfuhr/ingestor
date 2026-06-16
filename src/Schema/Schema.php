<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Schema;

use Ivanfuhr\Ingestor\Contract\Definition;

final class Schema
{
    /** @var array<string, DatasetConfig> */
    private array $datasets = [];

    private ?DatasetBuilder $datasetBuilder = null;

    public static function make(): self
    {
        return new self();
    }

    /**
     * @return array<string, DatasetConfig>
     */
    public static function datasetsFromDefinition(Definition $definition): array
    {
        $resolved = $definition->schema();

        if ($resolved instanceof DatasetBuilder) {
            $resolved = $resolved->commit();
        }

        return $resolved->datasets();
    }

    public function dataset(string $name): DatasetBuilder
    {
        $this->commitPending();

        $this->datasetBuilder = new DatasetBuilder($this, $name);

        return $this->datasetBuilder;
    }

    /**
     * @internal
     */
    public function registerDataset(string $name, DatasetConfig $datasetConfig): void
    {
        $this->datasets[$name] = $datasetConfig;
        $this->datasetBuilder = null;
    }

    /**
     * @return array<string, DatasetConfig>
     */
    public function datasets(): array
    {
        $this->commitPending();

        return $this->datasets;
    }

    private function commitPending(): void
    {
        if (!$this->datasetBuilder instanceof DatasetBuilder) {
            return;
        }

        $this->datasetBuilder->commit();
    }
}
