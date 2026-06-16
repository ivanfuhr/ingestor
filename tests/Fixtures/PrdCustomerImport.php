<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\DatasetBuilder;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use Ivanfuhr\Ingestor\Validation\Failure;

final class PrdCustomerImport implements Definition, ValidatesRows
{
    public function schema(): Schema|DatasetBuilder
    {
        return Schema::make()
            ->dataset('customers')
                ->using(PrefilledStage::class)
                ->onConflict(UpdateOnConflict::by('customer_id'));
    }

    public function validate(Row $row, Context $context): iterable
    {
        if ($row->missing('customer_id')) {
            yield Failure::error('customer_id')
                ->message('Customer ID is required.');
        }
    }

    public function map(Row $row, Context $context): Dataset
    {
        return Dataset::make()->insert('customers', [
            'customer_id' => $row->string('customer_id'),
            'customer_unique_id' => $row->string('customer_unique_id'),
            'customer_zip_code_prefix' => $row->int('customer_zip_code_prefix'),
            'customer_city' => $row->string('customer_city'),
            'customer_state' => $row->string('customer_state'),
        ]);
    }
}
