<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Testing;

use Generator;
use Ivanfuhr\Ingestor\Conflict\ConflictType;
use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Context\ArrayContext;
use Ivanfuhr\Ingestor\Context\ArrayRowContext;
use Ivanfuhr\Ingestor\Contract\AfterImport;
use Ivanfuhr\Ingestor\Contract\BeforeImport;
use Ivanfuhr\Ingestor\Contract\DatasetMetrics;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\Metrics;
use Ivanfuhr\Ingestor\Contract\Preparable;
use Ivanfuhr\Ingestor\Contract\RowContext;
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Dataset\InsertMutation;
use Ivanfuhr\Ingestor\ImportResult;
use Ivanfuhr\Ingestor\Metrics\MetricsRecorder;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\DatasetConfig;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Validation\FailureWithLine;
use Ivanfuhr\Ingestor\Validation\Severity;
use PHPUnit\Framework\Assert as PHPUnit;

final class DefinitionTest
{
    private ?string $currentDataset = null;

    /** @var array<string, mixed> */
    private array $contextSeed = [];

    private ?Dataset $lastDataset = null;

    /** @var list<Failure> */
    private array $lastFailures = [];

    private ?ImportResult $lastResult = null;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public function __construct(
        private readonly Definition $definition,
    ) {
    }

    public static function for(Definition $definition): self
    {
        return new self($definition);
    }

    public function assertDataset(string $name): self
    {
        $datasets = Schema::datasetsFromDefinition($this->definition);

        Assert::true(
            array_key_exists($name, $datasets),
            sprintf('Dataset "%s" is not registered in the schema.', $name),
        );

        $this->currentDataset = $name;

        return $this;
    }

    /**
     * @param class-string $stageStrategyClass
     */
    public function assertStage(string $stageStrategyClass): self
    {
        $config = $this->datasetConfig();

        Assert::true(
            is_a($config->stageStrategy, $stageStrategyClass),
            sprintf(
                'Expected stage strategy "%s" for dataset "%s", got "%s".',
                $stageStrategyClass,
                $this->currentDataset,
                $config->stageStrategy::class,
            ),
        );

        return $this;
    }

    public function assertUpdateOnConflict(string ...$columns): self
    {
        $config = $this->datasetConfig();
        $strategy = $config->conflictStrategy;

        if (!$strategy instanceof UpdateOnConflict) {
            PHPUnit::fail(sprintf(
                'Expected update-on-conflict strategy for dataset "%s", got %s.',
                $this->currentDataset,
                $strategy === null ? 'none' : $strategy::class,
            ));
        }

        Assert::true(
            $strategy->type() === ConflictType::Update,
            sprintf('Expected update-on-conflict strategy for dataset "%s".', $this->currentDataset),
        );

        Assert::same(
            $columns,
            $strategy->columns(),
            sprintf(
                'Expected conflict columns [%s] for dataset "%s", got [%s].',
                implode(', ', $columns),
                $this->currentDataset,
                implode(', ', $strategy->columns()),
            ),
        );

        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        $this->contextSeed = array_merge($this->contextSeed, $context);

        return $this;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row): self
    {
        $context = $this->buildContext();
        $this->lastFailures = [];
        $this->lastDataset = null;
        $this->lastResult = null;

        if ($this->definition instanceof ValidatesRows) {
            $rowObject = Row::make(1, $row);

            foreach ($this->definition->validate($rowObject, $context) as $failure) {
                $this->lastFailures[] = FailureWithLine::from($failure, $rowObject->line());
            }

            if ($this->hasValidationErrors()) {
                return $this;
            }
        }

        $this->lastDataset = $this->definition->map(Row::make(1, $row), $context);

        return $this;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function assertInserted(string $dataset, ?array $data = null): self
    {
        $mutations = $this->mutationsForDataset($dataset);

        Assert::true(
            $mutations !== [],
            sprintf('No insertions were produced for dataset "%s".', $dataset),
        );

        if ($data === null) {
            return $this;
        }

        foreach ($mutations as $mutation) {
            if ($mutation->data === $data) {
                return $this;
            }
        }

        PHPUnit::fail(sprintf(
            'No insertion matching the expected data was produced for dataset "%s".',
            $dataset,
        ));
    }

    public function assertDatasetCount(string $dataset, int $count): self
    {
        if ($this->lastResult instanceof ImportResult) {
            $metrics = $this->datasetMetrics($dataset);

            if ($metrics === null) {
                PHPUnit::fail(sprintf('Dataset "%s" was not produced during import.', $dataset));
            }

            $mutationCount = $metrics->mutations();

            Assert::same(
                $count,
                $mutationCount,
                sprintf(
                    'Expected %d mutation(s) for dataset "%s", got %d.',
                    $count,
                    $dataset,
                    $mutationCount,
                ),
            );

            return $this;
        }

        Assert::notNull(
            $this->lastDataset,
            'map() must be called before asserting dataset counts.',
        );

        $actual = count($this->mutationsForDataset($dataset));

        Assert::same(
            $count,
            $actual,
            sprintf(
                'Expected %d insertion(s) for dataset "%s", got %d.',
                $count,
                $dataset,
                $actual,
            ),
        );

        return $this;
    }

    public function assertFailure(?string $field = null, ?string $message = null): self
    {
        foreach ($this->lastFailures as $failure) {
            if ($field !== null && $failure->field() !== $field) {
                continue;
            }

            if ($message !== null && $failure->message() !== $message) {
                continue;
            }

            return $this;
        }

        PHPUnit::fail(sprintf(
            'Expected validation failure%s%s was not found.',
            $field !== null ? sprintf(' for field "%s"', $field) : '',
            $message !== null ? sprintf(' with message "%s"', $message) : '',
        ));
    }

    public function assertFailureCount(int $count): self
    {
        Assert::same(
            $count,
            count($this->lastFailures),
            sprintf('Expected %d validation failure(s), got %d.', $count, count($this->lastFailures)),
        );

        return $this;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function fromRows(array $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    public function import(): self
    {
        $context = $this->buildContext();
        $persistence = new InMemoryPersistenceDriver();
        $metrics = new MetricsRecorder();
        $metrics->registerDatasets(Schema::datasetsFromDefinition($this->definition));

        /** @var list<Failure> $failures */
        $failures = [];

        $stage = $persistence->begin($this->definition, $context);

        try {
            $rows = $this->countedRows($this->rowContexts(), $metrics);

            if ($this->definition instanceof ValidatesRows) {
                $rows = $this->validatedRows($this->definition, $rows, $context, $failures, $metrics);
            }

            $persistenceFailures = $persistence->ingest($stage, $rows, $metrics);
            array_push($failures, ...$persistenceFailures);

            $this->recordPersistenceRowFailures($persistenceFailures, $metrics);
        } finally {
            $metrics->finish();
        }

        $this->lastResult = new ImportResult($persistence, $stage, $metrics->snapshot(), $failures);
        $this->lastDataset = null;
        $this->lastFailures = [];

        if ($this->definition instanceof AfterImport) {
            $this->definition->afterImport($this->lastResult);
        }

        return $this;
    }

    public function assertRows(int $count): self
    {
        Assert::same(
            $count,
            $this->metrics()->rows(),
            sprintf('Expected %d row(s), got %d.', $count, $this->metrics()->rows()),
        );

        return $this;
    }

    public function assertImportedRows(int $count): self
    {
        Assert::same(
            $count,
            $this->metrics()->importedRows(),
            sprintf('Expected %d imported row(s), got %d.', $count, $this->metrics()->importedRows()),
        );

        return $this;
    }

    public function assertFailedRows(int $count): self
    {
        Assert::same(
            $count,
            $this->metrics()->failedRows(),
            sprintf('Expected %d failed row(s), got %d.', $count, $this->metrics()->failedRows()),
        );

        return $this;
    }

    public function assertMutations(int $count): self
    {
        Assert::same(
            $count,
            $this->metrics()->mutations(),
            sprintf('Expected %d mutation(s), got %d.', $count, $this->metrics()->mutations()),
        );

        return $this;
    }

    private function buildContext(): ArrayContext
    {
        $context = new ArrayContext();

        if ($this->definition instanceof BeforeImport) {
            $this->definition->beforeImport($context);
        }

        if ($this->definition instanceof Preparable) {
            $this->definition->prepare($context);
        }

        $this->seedContext($context);

        return $context;
    }

    private function seedContext(ArrayContext $context): void
    {
        foreach ($this->contextSeed as $key => $value) {
            $context->put($key, $value);
        }
    }

    /**
     * @return Generator<int, RowContext>
     */
    private function rowContexts(): Generator
    {
        $line = 1;

        foreach ($this->rows as $row) {
            yield new ArrayRowContext($line++, $row);
        }
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
        ArrayContext $context,
        array &$failures,
        MetricsRecorder $metrics,
    ): Generator {
        foreach ($rows as $rowContext) {
            $hasError = false;
            $row = Row::fromContext($rowContext);

            foreach ($definition->validate($row, $context) as $failure) {
                $failures[] = FailureWithLine::from($failure, $row->line());

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

    private function datasetConfig(): DatasetConfig
    {
        $dataset = $this->currentDataset;

        if ($dataset === null) {
            PHPUnit::fail('assertDataset() must be called before inspecting dataset configuration.');
        }

        $datasets = Schema::datasetsFromDefinition($this->definition);

        return $datasets[$dataset];
    }

    private function hasValidationErrors(): bool
    {
        foreach ($this->lastFailures as $failure) {
            if ($failure->severity() === Severity::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<InsertMutation>
     */
    private function mutationsForDataset(string $dataset): array
    {
        $lastDataset = $this->lastDataset;

        if (!$lastDataset instanceof Dataset) {
            PHPUnit::fail('map() must be called before asserting insertions.');
        }

        return array_values(array_filter(
            $lastDataset->mutations(),
            static fn (InsertMutation $mutation) => $mutation->dataset === $dataset,
        ));
    }

    private function metrics(): Metrics
    {
        $result = $this->lastResult;

        if (!$result instanceof ImportResult) {
            PHPUnit::fail('import() must be called before asserting import metrics.');
        }

        return $result->metrics();
    }

    private function datasetMetrics(string $name): ?DatasetMetrics
    {
        foreach ($this->metrics()->datasets() as $dataset) {
            if ($dataset->name() === $name) {
                return $dataset;
            }
        }

        return null;
    }
}
