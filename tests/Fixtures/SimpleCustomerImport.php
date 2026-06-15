<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;

final class SimpleCustomerImport implements Definition
{
    public function schema(): Schema
    {
        return Schema::make()
            ->dataset('customers')
                ->using(EmptyStage::class);
    }

    public function map(Row $row, Context $context): Dataset
    {
        return Dataset::make()->insert('customers', $row->toArray());
    }
}
