<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Testing;

use Ivanfuhr\Ingestor\Ingestor;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use Ivanfuhr\Ingestor\Tests\Fixtures\ContextualCustomerImport;
use Ivanfuhr\Ingestor\Tests\Fixtures\OrderImport;
use Ivanfuhr\Ingestor\Tests\Fixtures\PrdCustomerImport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefinitionTestTest extends TestCase
{
    #[Test]
    public function it_inspects_schema_configuration(): void
    {
        Ingestor::test(PrdCustomerImport::class)
            ->assertDataset('customers')
            ->assertStage(PrefilledStage::class)
            ->assertUpdateOnConflict('customer_id');
    }

    #[Test]
    public function it_populates_context_artificially(): void
    {
        Ingestor::test(ContextualCustomerImport::class)
            ->withContext([
                'customers' => [
                    '12345678901' => 1,
                ],
            ])
            ->map([
                'customer_unique_id' => '12345678901',
            ])
            ->assertInserted('customers', [
                'customer_id' => 1,
                'customer_unique_id' => '12345678901',
            ]);
    }

    #[Test]
    public function it_maps_a_single_row_in_isolation(): void
    {
        Ingestor::test(PrdCustomerImport::class)
            ->map([
                'customer_id' => '1',
                'customer_unique_id' => 'abc',
                'customer_zip_code_prefix' => '12345',
                'customer_city' => 'Lajeado',
                'customer_state' => 'RS',
            ])
            ->assertInserted('customers', [
                'customer_id' => '1',
                'customer_unique_id' => 'abc',
                'customer_zip_code_prefix' => 12345,
                'customer_city' => 'Lajeado',
                'customer_state' => 'RS',
            ]);
    }

    #[Test]
    public function it_asserts_multiple_datasets_from_a_single_row(): void
    {
        $row = [
            'order_id' => '100',
            'items' => [
                ['product_id' => 'A', 'quantity' => 1],
                ['product_id' => 'B', 'quantity' => 2],
                ['product_id' => 'C', 'quantity' => 3],
            ],
        ];

        Ingestor::test(OrderImport::class)
            ->map($row)
            ->assertInserted('orders')
            ->assertInserted('order_items')
            ->assertDatasetCount('orders', 1)
            ->assertDatasetCount('order_items', 3);
    }

    #[Test]
    public function it_inspects_validation_failures(): void
    {
        Ingestor::test(PrdCustomerImport::class)
            ->map([
                'customer_id' => null,
            ])
            ->assertFailure(
                field: 'customer_id',
                message: 'Customer ID is required.',
            )
            ->assertFailureCount(1);
    }

    #[Test]
    public function it_simulates_a_complete_import_from_memory(): void
    {
        Ingestor::test(PrdCustomerImport::class)
            ->fromRows([
                [
                    'customer_id' => '1',
                    'customer_unique_id' => 'abc',
                    'customer_zip_code_prefix' => '12345',
                    'customer_city' => 'Lajeado',
                    'customer_state' => 'RS',
                ],
            ])
            ->import()
            ->assertImportedRows(1)
            ->assertFailedRows(0)
            ->assertDatasetCount('customers', 1);
    }

    #[Test]
    public function it_asserts_import_metrics(): void
    {
        $rows = [];

        for ($index = 1; $index <= 500; ++$index) {
            $rows[] = [
                'customer_id' => (string) $index,
                'customer_unique_id' => 'id-' . $index,
                'customer_zip_code_prefix' => '12345',
                'customer_city' => 'Lajeado',
                'customer_state' => 'RS',
            ];
        }

        $rows[0]['customer_id'] = null;
        $rows[1]['customer_id'] = null;

        Ingestor::test(PrdCustomerImport::class)
            ->fromRows($rows)
            ->import()
            ->assertRows(500)
            ->assertImportedRows(498)
            ->assertFailedRows(2)
            ->assertMutations(498);
    }
}
