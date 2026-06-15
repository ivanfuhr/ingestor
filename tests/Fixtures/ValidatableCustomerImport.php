<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\Preparable;
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Validation\Failure;

final class ValidatableCustomerImport implements Definition, Preparable, ValidatesRows
{
    public function schema(): Schema
    {
        return Schema::make()
            ->dataset('customers')
                ->using(EmptyStage::class);
    }

    public function prepare(Context $context): void
    {
        $context->put('cities', [
            'SP' => true,
            'RJ' => true,
        ]);
    }

    public function validate(Row $row, Context $context): iterable
    {
        if ($row->missing('document')) {
            yield Failure::error('document')
                ->message('Document is required.');
        }

        if ($row->missing('phone')) {
            yield Failure::warning('phone')
                ->message('Phone number is empty.');
        }

        if ($row->filled('city') && !$context->has('cities', $row->string('city'))) {
            yield Failure::error('city')
                ->message('City not found.');
        }
    }

    public function map(Row $row, Context $context): Dataset
    {
        return Dataset::make()->insert('customers', [
            'document' => $row->string('document'),
            'name' => $row->string('name'),
            'phone' => $row->string('phone'),
            'city' => $row->string('city'),
        ]);
    }
}
