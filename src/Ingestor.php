<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor;

use InvalidArgumentException;
use LogicException;
use Throwable;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Contract\SourceDriver;

final class Ingestor
{
    private ?Definition $definition = null;

    private mixed $importSource = null;

    public function __construct(
        private readonly PersistenceDriver $persistence,
        private readonly SourceDriver $source,
    ) {
    }

    /**
     * @param class-string<Definition> $definitionClass
     */
    public function for(string $definitionClass): self
    {
        if (!is_subclass_of($definitionClass, Definition::class) && $definitionClass !== Definition::class) {
            throw new InvalidArgumentException(sprintf('"%s" must implement Definition.', $definitionClass));
        }

        /** @var Definition $instance */
        $instance = new $definitionClass();
        $this->definition = $instance;

        return $this;
    }

    public function from(mixed $source): self
    {
        $this->importSource = $source;

        return $this;
    }

    public function import(): ImportResult
    {
        if (!$this->definition instanceof Definition) {
            throw new LogicException('A definition must be provided via for() before importing.');
        }

        if ($this->importSource === null) {
            throw new LogicException('A source must be provided via from() before importing.');
        }

        $stage = $this->persistence->begin($this->definition);

        try {
            $rows = $this->source->read($this->importSource);
            $this->persistence->ingest($stage, $rows);
        } catch (Throwable $throwable) {
            $this->persistence->rollback($stage);

            throw $throwable;
        }

        return new ImportResult($this->persistence, $stage);
    }
}
