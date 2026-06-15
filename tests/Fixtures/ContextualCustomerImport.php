<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;

final class ContextualCustomerImport implements Definition
{
    public function schema(): Schema
    {
        return Schema::make()
            ->dataset('customers')
                ->using(EmptyStage::class);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row, Context $context): Dataset
    {
        /** @var array<string, int> $customers */
        $customers = $context->get('customers');

        return Dataset::make()->insert('customers', [
            'customer_id' => $customers[$row['customer_unique_id']] ?? null,
            'customer_unique_id' => $row['customer_unique_id'],
        ]);
    }
}
