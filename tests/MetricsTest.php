<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests;

use DateTimeImmutable;
use Ivanfuhr\Ingestor\Conflict\ConflictType;
use Ivanfuhr\Ingestor\Context\ArrayRowContext;
use Ivanfuhr\Ingestor\Contract\AfterRelease;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\ImportedImport;
use Ivanfuhr\Ingestor\Contract\Metrics;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Contract\ReleasedImport;
use Ivanfuhr\Ingestor\Contract\SourceDriver;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Duration;
use Ivanfuhr\Ingestor\Ingestor;
use Ivanfuhr\Ingestor\Metrics\DatasetMetricsSnapshot;
use Ivanfuhr\Ingestor\Metrics\ImportMetrics;
use Ivanfuhr\Ingestor\Metrics\MetricsRecorder;
use Ivanfuhr\Ingestor\Persistence\Failure as PersistenceFailure;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\DatasetBuilder;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use Ivanfuhr\Ingestor\Stage\Stage;
use Ivanfuhr\Ingestor\Tests\Fixtures\CustomerImport;
use Ivanfuhr\Ingestor\Tests\Fixtures\SimpleCustomerImport;
use Ivanfuhr\Ingestor\Tests\Fixtures\ValidatableCustomerImport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    #[Test]
    public function it_calculates_duration_between_timestamps(): void
    {
        $start = new DateTimeImmutable('2026-01-01 10:00:00.500000');
        $end = new DateTimeImmutable('2026-01-01 10:01:42.250000');

        $duration = Duration::between($start, $end);

        $this->assertSame(101, $duration->seconds());
        $this->assertSame(750000, $duration->microseconds());
        $this->assertEqualsWithDelta(101.75, $duration->totalSeconds(), 0.000001);
    }

    #[Test]
    public function it_exposes_import_metrics_from_import_result(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
                yield new ArrayRowContext(2, ['document' => '2', 'name' => 'Bob']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows, MetricsRecorder $metrics): array
            {
                foreach ($rows as $rowContext) {
                    $dataset = $stage->definition->map(Row::fromContext($rowContext), $stage->context);

                    foreach ($dataset->mutations() as $mutation) {
                        $metrics->recordMutation($mutation->dataset);
                        $metrics->recordPersisted($mutation->dataset);
                    }
                }

                return [];
            }

            public function release(Stage $stage): void
            {
            }

            public function rollback(Stage $stage): void
            {
            }
        };

        $ingestor = Ingestor::make($persistence, $source);

        $result = $ingestor
            ->for(SimpleCustomerImport::class)
            ->from('ignored')
            ->import();

        $metrics = $result->metrics();

        $this->assertInstanceOf(Metrics::class, $metrics);
        $this->assertSame(2, $metrics->rows());
        $this->assertSame(2, $metrics->importedRows());
        $this->assertSame(0, $metrics->failedRows());
        $this->assertSame(2, $metrics->mutations());
        $this->assertNotNull($metrics->startedAt());
        $this->assertNotNull($metrics->finishedAt());
        $this->assertGreaterThanOrEqual(0, $metrics->duration()->seconds());

        $datasets = $metrics->datasets();
        $this->assertCount(1, $datasets);
        $this->assertSame('customers', $datasets[0]->name());
        $this->assertSame(EmptyStage::class, $datasets[0]->stageStrategy());
        $this->assertNull($datasets[0]->onConflict());
        $this->assertSame([], $datasets[0]->onConflictColumns());
        $this->assertSame(2, $datasets[0]->mutations());
        $this->assertSame(2, $datasets[0]->persisted());
        $this->assertSame(0, $datasets[0]->failures());
    }

    #[Test]
    public function it_counts_validation_failures_in_metrics(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '', 'name' => 'Invalid', 'phone' => '123', 'city' => 'SP']);
                yield new ArrayRowContext(2, ['document' => '111', 'name' => 'Valid', 'phone' => '', 'city' => 'SP']);
                yield new ArrayRowContext(3, ['document' => '222', 'name' => 'Unknown city', 'phone' => '456', 'city' => 'XX']);
            }
        };

        $persistence = $this->mappingPersistence();

        $ingestor = Ingestor::make($persistence, $source);

        $result = $ingestor
            ->for(ValidatableCustomerImport::class)
            ->from('ignored')
            ->import();

        $metrics = $result->metrics();

        $this->assertSame(3, $metrics->rows());
        $this->assertSame(2, $metrics->failedRows());
        $this->assertSame(1, $metrics->importedRows());
        $this->assertSame(1, $metrics->mutations());

        $datasets = $metrics->datasets();
        $this->assertSame(1, $datasets[0]->persisted());
    }

    #[Test]
    public function it_counts_persistence_failures_in_metrics(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows, MetricsRecorder $metrics): array
            {
                foreach ($rows as $rowContext) {
                    $dataset = $stage->definition->map(Row::fromContext($rowContext), $stage->context);

                    foreach ($dataset->mutations() as $mutation) {
                        $metrics->recordMutation($mutation->dataset);
                        $metrics->recordDatasetFailure($mutation->dataset);
                    }
                }

                return [
                    PersistenceFailure::fromException(
                        line: 1,
                        dataset: 'customers',
                        data: ['document' => '1', 'name' => 'Ada'],
                        cause: new \PDOException('constraint violation'),
                    ),
                ];
            }

            public function release(Stage $stage): void
            {
            }

            public function rollback(Stage $stage): void
            {
            }
        };

        $ingestor = Ingestor::make($persistence, $source);

        $result = $ingestor
            ->for(SimpleCustomerImport::class)
            ->from('ignored')
            ->import();

        $metrics = $result->metrics();

        $this->assertSame(1, $metrics->rows());
        $this->assertSame(1, $metrics->failedRows());
        $this->assertSame(0, $metrics->importedRows());

        $datasets = $metrics->datasets();
        $this->assertSame(1, $datasets[0]->failures());
        $this->assertSame(0, $datasets[0]->persisted());
    }

    #[Test]
    public function it_keeps_metrics_available_after_release(): void
    {
        $definition = new class () implements Definition, AfterRelease {
            public static ?Metrics $releasedMetrics = null;

            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class);
            }

            public function afterRelease(ReleasedImport $import): void
            {
                self::$releasedMetrics = $import->metrics();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', $row->toArray());
            }
        };

        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = $this->mappingPersistence();

        $ingestor = Ingestor::make($persistence, $source);

        $result = $ingestor
            ->for($definition::class)
            ->from('ignored')
            ->import();

        $importMetrics = $result->metrics();

        $result->release();

        $this->assertNotNull($definition::class::$releasedMetrics);
        $this->assertSame($importMetrics->rows(), $definition::class::$releasedMetrics->rows());
        $this->assertSame($importMetrics->mutations(), $definition::class::$releasedMetrics->mutations());
        $this->assertSame($importMetrics->startedAt()->format('U.u'), $definition::class::$releasedMetrics->startedAt()->format('U.u'));
    }

    #[Test]
    public function it_keeps_metrics_available_after_rollback(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = $this->mappingPersistence();

        $ingestor = Ingestor::make($persistence, $source);

        $result = $ingestor
            ->for(SimpleCustomerImport::class)
            ->from('ignored')
            ->import();

        $metricsBeforeRollback = $result->metrics();

        $result->rollback();

        $this->assertSame(1, $metricsBeforeRollback->rows());
        $this->assertSame(1, $metricsBeforeRollback->importedRows());
    }

    #[Test]
    public function it_builds_import_metrics_snapshot(): void
    {
        $startedAt = new DateTimeImmutable('2026-01-01 10:00:00');
        $finishedAt = new DateTimeImmutable('2026-01-01 10:01:42');

        $metrics = new ImportMetrics(
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            rows: 500_000,
            importedRows: 499_812,
            failedRows: 188,
            mutations: 842_195,
            datasets: [
                new DatasetMetricsSnapshot('customers', EmptyStage::class, null, [], 500_000, 499_812, 188),
                new DatasetMetricsSnapshot('addresses', EmptyStage::class, null, [], 500_000, 500_000, 0),
                new DatasetMetricsSnapshot('phones', EmptyStage::class, null, [], 342_195, 342_195, 0),
            ],
        );

        $this->assertSame($startedAt, $metrics->startedAt());
        $this->assertSame($finishedAt, $metrics->finishedAt());
        $this->assertSame(102, $metrics->duration()->seconds());
        $this->assertSame(500_000, $metrics->rows());
        $this->assertSame(499_812, $metrics->importedRows());
        $this->assertSame(188, $metrics->failedRows());
        $this->assertSame(842_195, $metrics->mutations());
        $this->assertCount(3, $metrics->datasets());
    }

    #[Test]
    public function it_exposes_dataset_stage_and_on_conflict_metadata_from_schema(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(PrefilledStage::class)
                        ->onConflict(\Ivanfuhr\Ingestor\Conflict\UpdateOnConflict::by('document'))
                    ->dataset('addresses')
                        ->using(EmptyStage::class);
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['cpf' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = $this->mappingPersistence();

        $ingestor = Ingestor::make($persistence, $source);

        $result = $ingestor
            ->for($definition::class)
            ->from('ignored')
            ->import();

        $datasets = $result->metrics()->datasets();
        $this->assertCount(2, $datasets);

        $customers = $datasets[0];
        $this->assertSame('customers', $customers->name());
        $this->assertSame(PrefilledStage::class, $customers->stageStrategy());
        $this->assertSame(ConflictType::Update, $customers->onConflict());
        $this->assertSame(['document'], $customers->onConflictColumns());
        $this->assertSame(1, $customers->mutations());

        $addresses = $datasets[1];
        $this->assertSame('addresses', $addresses->name());
        $this->assertSame(EmptyStage::class, $addresses->stageStrategy());
        $this->assertNull($addresses->onConflict());
        $this->assertSame([], $addresses->onConflictColumns());
        $this->assertSame(0, $addresses->mutations());
    }

    #[Test]
    public function it_exposes_dataset_metadata_for_customer_import_fixture(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['cpf' => '1', 'name' => 'Ada', 'city' => 'SP']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, [
                    'customers' => 'stage_customers',
                    'addresses' => 'stage_addresses',
                ], $context);
            }

            public function ingest(Stage $stage, iterable $rows, MetricsRecorder $metrics): array
            {
                foreach ($rows as $rowContext) {
                    $dataset = $stage->definition->map(Row::fromContext($rowContext), $stage->context);

                    foreach ($dataset->mutations() as $mutation) {
                        $metrics->recordMutation($mutation->dataset);
                        $metrics->recordPersisted($mutation->dataset);
                    }
                }

                return [];
            }

            public function release(Stage $stage): void
            {
            }

            public function rollback(Stage $stage): void
            {
            }
        };

        $ingestor = Ingestor::make($persistence, $source);

        $result = $ingestor
            ->for(CustomerImport::class)
            ->from('ignored')
            ->import();

        $datasets = $result->metrics()->datasets();
        $this->assertCount(2, $datasets);

        $customers = $datasets[0];
        $this->assertSame('customers', $customers->name());
        $this->assertSame(PrefilledStage::class, $customers->stageStrategy());
        $this->assertSame(ConflictType::Update, $customers->onConflict());
        $this->assertSame(['document'], $customers->onConflictColumns());

        $addresses = $datasets[1];
        $this->assertSame('addresses', $addresses->name());
        $this->assertSame(EmptyStage::class, $addresses->stageStrategy());
        $this->assertNull($addresses->onConflict());
        $this->assertSame([], $addresses->onConflictColumns());
    }

    /**
     * @return PersistenceDriver
     */
    private function mappingPersistence(): object
    {
        return new class () implements PersistenceDriver {
            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows, MetricsRecorder $metrics): array
            {
                foreach ($rows as $rowContext) {
                    $dataset = $stage->definition->map(Row::fromContext($rowContext), $stage->context);

                    foreach ($dataset->mutations() as $mutation) {
                        $metrics->recordMutation($mutation->dataset);
                        $metrics->recordPersisted($mutation->dataset);
                    }
                }

                return [];
            }

            public function release(Stage $stage): void
            {
            }

            public function rollback(Stage $stage): void
            {
            }
        };
    }
}
