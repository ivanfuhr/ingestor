<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor;

use InvalidArgumentException;
use LogicException;
use Throwable;
use Generator;
use Ivanfuhr\Ingestor\Context\ArrayContext;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Contract\Preparable;
use Ivanfuhr\Ingestor\Contract\SourceDriver;
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Validation\Severity;

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

        $context = new ArrayContext();

        if ($this->definition instanceof Preparable) {
            $this->definition->prepare($context);
        }

        $stage = $this->persistence->begin($this->definition, $context);

        /** @var list<Failure> $errors */
        $errors = [];

        try {
            $rows = $this->source->read($this->importSource);

            if ($this->definition instanceof ValidatesRows) {
                $rows = $this->validatedRows($this->definition, $rows, $context, $errors);
            }

            $this->persistence->ingest($stage, $rows);
        } catch (Throwable $throwable) {
            $this->persistence->rollback($stage);

            throw $throwable;
        }

        return new ImportResult($this->persistence, $stage, $errors);
    }

    /**
     * @param iterable<int, array<string, mixed>> $rows
     * @param list<Failure> $errors
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function validatedRows(
        ValidatesRows $definition,
        iterable $rows,
        Context $context,
        array &$errors,
    ): Generator {
        foreach ($rows as $row) {
            $hasError = false;

            foreach ($definition->validate($row, $context) as $failure) {
                $errors[] = $failure;

                if ($failure->severity() === Severity::ERROR) {
                    $hasError = true;
                }
            }

            if (!$hasError) {
                yield $row;
            }
        }
    }
}
