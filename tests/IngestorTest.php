<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Contract\Preparable;
use Ivanfuhr\Ingestor\Contract\SourceDriver;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Ingestor;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\Stage;
use Ivanfuhr\Ingestor\Tests\Fixtures\PreparableCustomerImport;
use Ivanfuhr\Ingestor\Tests\Fixtures\SimpleCustomerImport;
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
                yield ['document' => '1', 'name' => 'Ada'];
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

            public function ingest(Stage $stage, iterable $rows): void
            {
                foreach ($rows as $row) {
                    $this->ingestedRows[] = $row;
                }
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
                yield ['document' => '1', 'name' => 'Ada'];
            }
        };

        $persistence = new class () implements PersistenceDriver {
            public ?Stage $stage = null;

            public function begin(Definition $definition, Context $context): Stage
            {
                return $this->stage = new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): void
            {
                foreach ($rows as $row) {
                    $stage->definition->map($row, $stage->context);
                }
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
                yield ['document' => '111', 'name' => 'Ada'];
                yield ['document' => '222', 'name' => 'Bob'];
            }
        };

        $persistence = new class () implements PersistenceDriver {
            /** @var list<array<string, mixed>> */
            public array $mappedRows = [];

            public function begin(Definition $definition, Context $context): Stage
            {
                return new Stage('stage-1', $definition, ['customers' => 'stage_customers'], $context);
            }

            public function ingest(Stage $stage, iterable $rows): void
            {
                foreach ($rows as $row) {
                    $dataset = $stage->definition->map($row, $stage->context);

                    foreach ($dataset->mutations() as $mutation) {
                        $this->mappedRows[] = $mutation->data;
                    }
                }
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
}
