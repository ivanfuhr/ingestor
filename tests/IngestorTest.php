<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests;

use Ivanfuhr\Ingestor\Context\ArrayRowContext;
use Ivanfuhr\Ingestor\Contract\AfterImport;
use Ivanfuhr\Ingestor\Contract\AfterRelease;
use Ivanfuhr\Ingestor\Contract\BeforeImport;
use Ivanfuhr\Ingestor\Contract\BeforeRelease;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\ImportedImport;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Contract\Preparable;
use Ivanfuhr\Ingestor\Contract\ReleasedImport;
use Ivanfuhr\Ingestor\Contract\SourceDriver;
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Exception\CannotRelease;
use Ivanfuhr\Ingestor\Ingestor;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\Stage;
use Ivanfuhr\Ingestor\Tests\Fixtures\PreparableCustomerImport;
use Ivanfuhr\Ingestor\Tests\Fixtures\SimpleCustomerImport;
use Ivanfuhr\Ingestor\Tests\Fixtures\ValidatableCustomerImport;
use Ivanfuhr\Ingestor\Validation\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IngestorTest extends TestCase
{
    #[Test]
    public function it_orchestrates_import_and_release(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            public ?Stage $stage = null;

            /** @var list<array<string, mixed>> */
            public array $ingestedRows = [];

            public bool $released = false;

            public function begin(Definition $definition, Context $context): Stage
            {
                return $this->stage = new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): array
            {
                foreach ($rows as $rowContext) {
                    $this->ingestedRows[] = $rowContext->data();
                }

                return [];
            }

            public function release(Stage $stage): void
            {
                $this->released = true;
            }

            public function rollback(Stage $stage): void
            {
            }
        };

        $ingestor = new Ingestor($persistence, $source);

        $ingestor
            ->for(SimpleCustomerImport::class)
            ->from('ignored')
            ->import()
            ->release();

        $this->assertInstanceOf(Stage::class, $persistence->stage);
        $this->assertSame([['document' => '1', 'name' => 'Ada']], $persistence->ingestedRows);
        $this->assertTrue($persistence->released);
    }

    #[Test]
    public function it_calls_prepare_before_ingesting_rows(): void
    {
        $definition = new class () implements Definition, Preparable {
            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class);
            }

            public function prepare(Context $context): void
            {
                $context->put('marker', 'ready');
            }

            public function map(array $row, Context $context): Dataset
            {
                if (!$context->has('marker')) {
                    throw new \RuntimeException('Context was not prepared before mapping.');
                }

                return Dataset::make()->insert('customers', $row);
            }
        };

        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            public ?Stage $stage = null;

            public function begin(Definition $definition, Context $context): Stage
            {
                return $this->stage = new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): array
            {
                foreach ($rows as $rowContext) {
                    $stage->definition->map($rowContext->data(), $stage->context);
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

        $ingestor = new Ingestor($persistence, $source);

        $ingestor
            ->for($definition::class)
            ->from('ignored')
            ->import();

        $this->assertNotNull($persistence->stage);
        $this->assertTrue($persistence->stage->context->has('marker'));
        $this->assertSame('ready', $persistence->stage->context->get('marker'));
    }

    #[Test]
    public function it_makes_context_data_available_during_mapping(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '111', 'name' => 'Ada']);
                yield new ArrayRowContext(2, ['document' => '222', 'name' => 'Bob']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            /** @var list<array<string, mixed>> */
            public array $mappedRows = [];

            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): array
            {
                foreach ($rows as $rowContext) {
                    $dataset = $stage->definition->map($rowContext->data(), $stage->context);

                    foreach ($dataset->mutations() as $mutation) {
                        $this->mappedRows[] = $mutation->data;
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

        $ingestor = new Ingestor($persistence, $source);

        $ingestor
            ->for(PreparableCustomerImport::class)
            ->from('ignored')
            ->import();

        $this->assertSame([
            ['document' => '111', 'name' => 'Ada', 'customer_id' => 1],
            ['document' => '222', 'name' => 'Bob', 'customer_id' => 2],
        ], $persistence->mappedRows);
    }

    #[Test]
    public function it_skips_rows_with_validation_errors_and_collects_failures(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '', 'name' => 'Invalid', 'phone' => '123', 'city' => 'SP']);
                yield new ArrayRowContext(2, ['document' => '111', 'name' => 'Valid', 'phone' => '', 'city' => 'SP']);
                yield new ArrayRowContext(3, ['document' => '222', 'name' => 'Unknown city', 'phone' => '456', 'city' => 'XX']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            /** @var list<array<string, mixed>> */
            public array $mappedRows = [];

            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): array
            {
                foreach ($rows as $rowContext) {
                    $dataset = $stage->definition->map($rowContext->data(), $stage->context);

                    foreach ($dataset->mutations() as $mutation) {
                        $this->mappedRows[] = $mutation->data;
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

        $ingestor = new Ingestor($persistence, $source);

        $result = $ingestor
            ->for(ValidatableCustomerImport::class)
            ->from('ignored')
            ->import();

        $this->assertSame([
            [
                'document' => '111',
                'name' => 'Valid',
                'phone' => '',
                'city' => 'SP',
            ],
        ], $persistence->mappedRows);

        $failures = $result->failures();
        $this->assertTrue($result->hasFailures());
        $this->assertCount(3, $failures);

        $this->assertSame('document', $failures[0]->field());
        $this->assertSame('Document is required.', $failures[0]->message());
        $this->assertSame(Severity::ERROR, $failures[0]->severity());

        $this->assertSame('phone', $failures[1]->field());
        $this->assertSame('Phone number is empty.', $failures[1]->message());
        $this->assertSame(Severity::WARNING, $failures[1]->severity());

        $this->assertSame('city', $failures[2]->field());
        $this->assertSame('City not found.', $failures[2]->message());
        $this->assertSame(Severity::ERROR, $failures[2]->severity());
    }

    #[Test]
    public function it_validates_rows_after_prepare(): void
    {
        $definition = new class () implements Definition, Preparable, ValidatesRows {
            public static bool $validatedWithPreparedContext = false;

            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class);
            }

            public function prepare(Context $context): void
            {
                $context->put('marker', 'ready');
            }

            public function validate(array $row, Context $context): iterable
            {
                if (!$context->has('marker')) {
                    throw new \RuntimeException('Context was not prepared before validation.');
                }

                self::$validatedWithPreparedContext = true;

                yield from [];
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', $row);
            }
        };

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

            public function ingest(Stage $stage, iterable $rows): array
            {
                foreach ($rows as $rowContext) {
                    $stage->definition->map($rowContext->data(), $stage->context);
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

        $ingestor = new Ingestor($persistence, $source);

        $ingestor
            ->for($definition::class)
            ->from('ignored')
            ->import();

        $this->assertTrue($definition::class::$validatedWithPreparedContext);
    }

    #[Test]
    public function it_collects_persistence_failures_without_auto_rollback(): void
    {
        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            public bool $rolledBack = false;

            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): array
            {
                return [
                    \Ivanfuhr\Ingestor\Persistence\Failure::fromException(
                        line: 1,
                        dataset: 'customers',
                        data: ['document' => '1', 'name' => 'Ada'],
                        cause: new \PDOException('null value in column "document" violates not-null constraint'),
                    ),
                ];
            }

            public function release(Stage $stage): void
            {
            }

            public function rollback(Stage $stage): void
            {
                $this->rolledBack = true;
            }
        };

        $ingestor = new Ingestor($persistence, $source);

        $result = $ingestor
            ->for(SimpleCustomerImport::class)
            ->from('ignored')
            ->import();

        $this->assertFalse($persistence->rolledBack);
        $this->assertTrue($result->hasFailures());

        $failure = $result->failures()[0];
        $this->assertSame(1, $failure->line());
        $this->assertSame('customers', $failure->dataset());
        $this->assertSame('null value in column "document" violates not-null constraint', $failure->message());
        $this->assertInstanceOf(\PDOException::class, $failure->cause());
    }

    #[Test]
    public function it_calls_before_import_before_prepare(): void
    {
        $definition = new class () implements Definition, BeforeImport, Preparable {
            /** @var list<string> */
            public static array $events = [];

            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class);
            }

            public function beforeImport(Context $context): void
            {
                self::$events[] = 'beforeImport';
                $context->put('hook', 'started');
            }

            public function prepare(Context $context): void
            {
                self::$events[] = 'prepare';

                if (!$context->has('hook')) {
                    throw new \RuntimeException('beforeImport did not run before prepare.');
                }
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', $row);
            }
        };

        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = $this->noopPersistence();

        $ingestor = new Ingestor($persistence, $source);

        $ingestor
            ->for($definition::class)
            ->from('ignored')
            ->import();

        $this->assertSame(['beforeImport', 'prepare'], $definition::class::$events);
    }

    #[Test]
    public function it_calls_after_import_once_import_finishes(): void
    {
        $definition = new class () implements Definition, AfterImport {
            public static int $calls = 0;

            public static ?ImportedImport $import = null;

            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class);
            }

            public function afterImport(ImportedImport $import): void
            {
                ++self::$calls;
                self::$import = $import;
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', $row);
            }
        };

        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
                yield new ArrayRowContext(2, ['document' => '2', 'name' => 'Bob']);
            }
        };

        $persistence = $this->noopPersistence();

        $ingestor = new Ingestor($persistence, $source);

        $result = $ingestor
            ->for($definition::class)
            ->from('ignored')
            ->import();

        $this->assertSame(1, $definition::class::$calls);
        $this->assertSame($result, $definition::class::$import);
        $this->assertFalse($result->hasFailures());
    }

    #[Test]
    public function it_calls_release_hooks_in_order(): void
    {
        $definition = new class () implements Definition, BeforeRelease, AfterRelease {
            /** @var list<string> */
            public static array $events = [];

            public static ?ImportedImport $beforeReleaseImport = null;

            public static ?ReleasedImport $afterReleaseImport = null;

            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class);
            }

            public function beforeRelease(ImportedImport $import): void
            {
                self::$events[] = 'beforeRelease';
                self::$beforeReleaseImport = $import;
            }

            public function afterRelease(ReleasedImport $import): void
            {
                self::$events[] = 'afterRelease';
                self::$afterReleaseImport = $import;
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', $row);
            }
        };

        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            /** @var list<string> */
            public array $events = [];

            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): array
            {
                return [];
            }

            public function release(Stage $stage): void
            {
                $this->events[] = 'release';
            }

            public function rollback(Stage $stage): void
            {
            }
        };

        $ingestor = new Ingestor($persistence, $source);

        $result = $ingestor
            ->for($definition::class)
            ->from('ignored')
            ->import();

        $result->release();

        $this->assertSame(['beforeRelease', 'afterRelease'], $definition::class::$events);
        $this->assertSame(['release'], $persistence->events);
        $this->assertSame($result, $definition::class::$beforeReleaseImport);
        $this->assertNotNull($definition::class::$afterReleaseImport);
        $this->assertSame($result->stage(), $definition::class::$afterReleaseImport->stage());
    }

    #[Test]
    public function it_prevents_release_when_before_release_throws(): void
    {
        $definition = new class () implements Definition, BeforeRelease {
            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class);
            }

            public function beforeRelease(ImportedImport $import): void
            {
                throw CannotRelease::because('Import contains unresolved failures.');
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', $row);
            }
        };

        $source = new class () implements SourceDriver {
            public function read(mixed $source): iterable
            {
                yield new ArrayRowContext(1, ['document' => '1', 'name' => 'Ada']);
            }
        };

        $persistence = new class () implements PersistenceDriver {
            public bool $released = false;

            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): array
            {
                return [];
            }

            public function release(Stage $stage): void
            {
                $this->released = true;
            }

            public function rollback(Stage $stage): void
            {
            }
        };

        $ingestor = new Ingestor($persistence, $source);

        $result = $ingestor
            ->for($definition::class)
            ->from('ignored')
            ->import();

        $this->expectException(CannotRelease::class);
        $this->expectExceptionMessage('Import contains unresolved failures.');

        $result->release();

        $this->assertFalse($persistence->released);
    }

    /**
     * @return PersistenceDriver&object{stage: ?Stage}
     */
    private function noopPersistence(): object
    {
        return new class () implements PersistenceDriver {
            public ?Stage $stage = null;

            public function begin(Definition $definition, Context $context): Stage
            {
                return $this->stage = new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): array
            {
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
