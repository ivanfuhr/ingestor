<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests;

use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Contract\SourceDriver;
use Ivanfuhr\Ingestor\Ingestor;
use Ivanfuhr\Ingestor\Stage\Stage;
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

            public function begin(Definition $definition): Stage
            {
                return $this->stage = new Stage('stage-1', $definition, ['customers' => 'stage_customers']);
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
}
