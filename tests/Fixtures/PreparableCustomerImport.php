<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\Preparable;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;

final class PreparableCustomerImport implements Definition, Preparable
{
    public function schema(): Schema
    {
        return Schema::make()
            ->dataset('customers')
                ->using(EmptyStage::class);
    }

    public function prepare(Context $context): void
    {
        $context->put('customers', [
            '111' => 1,
            '222' => 2,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row, Context $context): Dataset
    {
        /** @var array<string, int> $customers */
        $customers = $context->get('customers');

        return Dataset::make()->insert('customers', [
            'document' => $row['document'],
            'name' => $row['name'],
            'customer_id' => $customers[$row['document']] ?? null,
        ]);
    }
}
