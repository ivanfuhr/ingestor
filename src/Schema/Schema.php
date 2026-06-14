<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Schema;

use Ivanfuhr\Ingestor\Conflict\ConflictStrategy;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\StageStrategy;

final class Schema
{
    /** @var array<string, DatasetConfig> */
    private array $datasets = [];

    private ?DatasetBuilder $datasetBuilder = null;

    public static function make(): self
    {
        return new self();
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

final class DatasetBuilder
{
    private StageStrategy $stageStrategy;

    private ?ConflictStrategy $conflictStrategy = null;

    public function __construct(
        private readonly Schema $schema,
        private readonly string $name,
    ) {
        $this->stageStrategy = new EmptyStage();
    }

    /**
     * @param class-string<StageStrategy> $stageStrategyClass
     */
    public function using(string $stageStrategyClass): self
    {
        $this->stageStrategy = new $stageStrategyClass();

        return $this;
    }

    public function onConflict(ConflictStrategy $conflictStrategy): Schema
    {
        $this->conflictStrategy = $conflictStrategy;

        return $this->commit();
    }

    /**
     * @internal
     */
    public function commit(): Schema
    {
        $this->schema->registerDataset(
            $this->name,
            new DatasetConfig($this->stageStrategy, $this->conflictStrategy),
        );

        return $this->schema;
    }
}

final readonly class DatasetConfig
{
    public function __construct(
        public StageStrategy $stageStrategy,
        public ?ConflictStrategy $conflictStrategy,
    ) {
    }
}
