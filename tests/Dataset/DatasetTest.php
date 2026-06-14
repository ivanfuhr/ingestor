<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Dataset;

use Ivanfuhr\Ingestor\Dataset\Dataset;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatasetTest extends TestCase
{
    #[Test]
    public function it_collects_insert_mutations(): void
    {
        $dataset = Dataset::make()
            ->insert('customers', ['document' => '123', 'name' => 'Ada'])
            ->insert('addresses', ['document' => '123', 'city' => 'SP']);

        $mutations = $dataset->mutations();

        $this->assertCount(2, $mutations);
        $this->assertSame('customers', $mutations[0]->dataset);
        $this->assertSame(['document' => '123', 'name' => 'Ada'], $mutations[0]->data);
        $this->assertSame('addresses', $mutations[1]->dataset);
    }
}
