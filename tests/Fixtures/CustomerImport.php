<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\DatasetBuilder;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;

final class CustomerImport implements Definition
{
    public function schema(): Schema|DatasetBuilder
    {
        return Schema::make()
            ->dataset('customers')
                ->using(PrefilledStage::class)
                ->onConflict(UpdateOnConflict::by('document'))
            ->dataset('addresses')
                ->using(EmptyStage::class);
    }

    public function map(Row $row, Context $context): Dataset
    {
        return Dataset::make()
            ->insert('customers', [
                'document' => $row->string('cpf'),
                'name' => $row->string('name'),
            ])
            ->insert('addresses', [
                'document' => $row->string('cpf'),
                'city' => $row->string('city'),
            ]);
    }
}
