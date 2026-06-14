<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor;

use InvalidArgumentException;
use LogicException;
use Throwable;
use Generator;
use Ivanfuhr\Ingestor\Context\ArrayContext;
use Ivanfuhr\Ingestor\Contract\AfterImport;
use Ivanfuhr\Ingestor\Contract\BeforeImport;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Contract\Preparable;
use Ivanfuhr\Ingestor\Contract\RowContext;
use Ivanfuhr\Ingestor\Contract\SourceDriver;
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Metrics\MetricsRecorder;
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

        if ($this->definition instanceof BeforeImport) {
            $this->definition->beforeImport($context);
        }

        if ($this->definition instanceof Preparable) {
            $this->definition->prepare($context);
        }

        $stage = $this->persistence->begin($this->definition, $context);

        /** @var list<Failure> $failures */
        $failures = [];

        $metrics = new MetricsRecorder();

        try {
            $rows = $this->countedRows($this->source->read($this->importSource), $metrics);

            if ($this->definition instanceof ValidatesRows) {
                $rows = $this->validatedRows($this->definition, $rows, $context, $failures, $metrics);
            }

            $persistenceFailures = $this->persistence->ingest($stage, $rows, $metrics);
            array_push($failures, ...$persistenceFailures);

            $this->recordPersistenceRowFailures($persistenceFailures, $metrics);
        } catch (Throwable $throwable) {
            $this->persistence->rollback($stage);

            throw $throwable;
        } finally {
            $metrics->finish();
        }

        $result = new ImportResult($this->persistence, $stage, $metrics->snapshot(), $failures);

        if ($this->definition instanceof AfterImport) {
            $this->definition->afterImport($result);
        }

        return $result;
    }

    /**
     * @param iterable<RowContext> $rows
     *
     * @return Generator<int, RowContext>
     */
    private function countedRows(iterable $rows, MetricsRecorder $metrics): Generator
    {
        foreach ($rows as $rowContext) {
            $metrics->recordRow();

            yield $rowContext;
        }
    }

    /**
     * @param iterable<RowContext> $rows
     * @param list<Failure> $failures
     *
     * @return Generator<int, RowContext>
     */
    private function validatedRows(
        ValidatesRows $definition,
        iterable $rows,
        Context $context,
        array &$failures,
        MetricsRecorder $metrics,
    ): Generator {
        foreach ($rows as $rowContext) {
            $hasError = false;

            foreach ($definition->validate($rowContext->data(), $context) as $failure) {
                $failures[] = $failure;

                if ($failure->severity() === Severity::ERROR) {
                    $hasError = true;
                }
            }

            if ($hasError) {
                $metrics->recordRowFailed();
            } else {
                yield $rowContext;
            }
        }
    }

    /**
     * @param list<Failure> $failures
     */
    private function recordPersistenceRowFailures(array $failures, MetricsRecorder $metrics): void
    {
        /** @var array<int, true> $failedLines */
        $failedLines = [];

        foreach ($failures as $failure) {
            $line = $failure->line();

            if ($line === null) {
                continue;
            }

            if (!isset($failedLines[$line])) {
                $failedLines[$line] = true;
                $metrics->recordRowFailed();
            }
        }
    }
}
