<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Schema;

use Ivanfuhr\Ingestor\Conflict\ConflictStrategy;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\StageStrategy;

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
