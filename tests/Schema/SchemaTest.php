<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Schema;

use Ivanfuhr\Ingestor\Conflict\IgnoreOnConflict;
use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    #[Test]
    public function it_registers_dataset_configuration(): void
    {
        $schema = Schema::make()
            ->dataset('customers')
                ->using(PrefilledStage::class)
                ->onConflict(UpdateOnConflict::by('document'))
            ->dataset('addresses')
                ->using(EmptyStage::class)
                ->onConflict(IgnoreOnConflict::by('document'));

        $datasets = $schema->datasets();

        $this->assertArrayHasKey('customers', $datasets);
        $this->assertInstanceOf(PrefilledStage::class, $datasets['customers']->stageStrategy);
        $this->assertSame(['document'], $datasets['customers']->conflictStrategy?->columns());

        $this->assertArrayHasKey('addresses', $datasets);
        $this->assertInstanceOf(EmptyStage::class, $datasets['addresses']->stageStrategy);
    }
}
