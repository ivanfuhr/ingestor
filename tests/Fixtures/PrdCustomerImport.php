<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use Ivanfuhr\Ingestor\Validation\Failure;

final class PrdCustomerImport implements Definition, ValidatesRows
{
    public function schema(): Schema
    {
        return Schema::make()
            ->dataset('customers')
                ->using(PrefilledStage::class)
                ->onConflict(UpdateOnConflict::by('customer_id'));
    }

    /**
     * @param array<string, mixed> $row
     */
    public function validate(array $row, Context $context): iterable
    {
        if (empty($row['customer_id'])) {
            yield Failure::error('customer_id')
                ->message('Customer ID is required.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row, Context $context): Dataset
    {
        return Dataset::make()->insert('customers', [
            'customer_id' => $row['customer_id'],
            'customer_unique_id' => $row['customer_unique_id'],
            'customer_zip_code_prefix' => (int) $row['customer_zip_code_prefix'],
            'customer_city' => $row['customer_city'],
            'customer_state' => $row['customer_state'],
        ]);
    }
}
