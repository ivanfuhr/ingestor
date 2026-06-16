<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Fixtures;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\DatasetBuilder;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;

final class OrderImport implements Definition
{
    public function schema(): Schema|DatasetBuilder
    {
        return Schema::make()
            ->dataset('orders')
                ->using(EmptyStage::class)
            ->dataset('order_items')
                ->using(EmptyStage::class);
    }

    public function map(Row $row, Context $context): Dataset
    {
        $dataset = Dataset::make()->insert('orders', [
            'order_id' => $row->string('order_id'),
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = $row->get('items');

        foreach ($items as $item) {
            $dataset->insert('order_items', [
                'order_id' => $row->string('order_id'),
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
            ]);
        }

        return $dataset;
    }
}
