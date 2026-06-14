<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\Preparable;
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Dataset\Dataset;
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

    /**
     * @param array<string, mixed> $row
     */
    public function validate(array $row, Context $context): iterable
    {
        if (empty($row['document'])) {
            yield Failure::error('document')
                ->message('Document is required.');
        }

        if (empty($row['phone'])) {
            yield Failure::warning('phone')
                ->message('Phone number is empty.');
        }

        /** @var array<string, bool> $cities */
        $cities = $context->get('cities');

        if (isset($row['city']) && !isset($cities[$row['city']])) {
            yield Failure::error('city')
                ->message('City not found.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row, Context $context): Dataset
    {
        return Dataset::make()->insert('customers', [
            'document' => $row['document'],
            'name' => $row['name'],
            'phone' => $row['phone'] ?? null,
            'city' => $row['city'] ?? null,
        ]);
    }
}
