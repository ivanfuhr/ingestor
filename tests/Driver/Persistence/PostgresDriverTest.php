<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Driver\Persistence;

use PDOException;
use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Driver\Persistence\PostgresDriver;
use Ivanfuhr\Ingestor\Driver\Source\CsvDriver;
use Ivanfuhr\Ingestor\Ingestor;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PostgresDriverTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $dsn = getenv('INGESTOR_TEST_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=ingestor_test';

        try {
            $this->pdo = new PDO($dsn, 'postgres', 'postgres', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException) {
            $this->markTestSkipped('PostgreSQL is not available for integration tests.');
        }

        $this->resetDatabase();
    }

    #[Test]
    public function it_imports_csv_into_postgres_and_releases(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->onConflict(UpdateOnConflict::by('document'));
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row['cpf'],
                    'name' => $row['name'],
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,Ada
222,Bob
CSV);

        $postgresDriver = new PostgresDriver($this->pdo);
        $ingestor = new Ingestor($postgresDriver, new CsvDriver());

        $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import()
            ->release();

        $rows = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['document' => '111', 'name' => 'Ada'],
            ['document' => '222', 'name' => 'Bob'],
        ], $rows);
    }

    #[Test]
    public function it_updates_existing_rows_on_conflict(): void
    {
        $this->pdo->exec("INSERT INTO customers (document, name) VALUES ('111', 'Old Name')");

        $definition = new class () implements Definition {
            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(PrefilledStage::class)
                        ->onConflict(UpdateOnConflict::by('document'));
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row['cpf'],
                    'name' => $row['name'],
                ]);
            }
        };

        $csv = $this->createCsv("cpf,name\n111,New Name\n");

        $ingestor = new Ingestor(new PostgresDriver($this->pdo), new CsvDriver());

        $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import()
            ->release();

        $row = $this->pdo->query("SELECT name FROM customers WHERE document = '111'")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(['name' => 'New Name'], $row);
    }

    #[Test]
    public function it_inserts_rows_in_chunks(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row['cpf'],
                    'name' => $row['name'],
                ]);
            }
        };

        $rows = [];
        $csvLines = ["cpf,name"];

        for ($index = 1; $index <= 5; ++$index) {
            $document = (string) $index;
            $rows[] = ['document' => $document, 'name' => 'Customer ' . $document];
            $csvLines[] = $document . ',Customer ' . $document;
        }

        $csv = $this->createCsv(implode("\n", $csvLines) . "\n");

        $ingestor = new Ingestor(new PostgresDriver($this->pdo, chunkSize: 2), new CsvDriver());

        $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import()
            ->release();

        $actual = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame($rows, $actual);
    }

    #[Test]
    public function it_chunks_inserts_across_multiple_datasets(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit()
                    ->dataset('addresses')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(array $row, Context $context): Dataset
            {
                return Dataset::make()
                    ->insert('customers', [
                        'document' => $row['cpf'],
                        'name' => $row['name'],
                    ])
                    ->insert('addresses', [
                        'document' => $row['cpf'],
                        'city' => $row['city'],
                    ]);
            }
        };

        $csvLines = ["cpf,name,city"];

        for ($index = 1; $index <= 4; ++$index) {
            $csvLines[] = sprintf('%d,Name %d,City %d', $index, $index, $index);
        }

        $csv = $this->createCsv(implode("\n", $csvLines) . "\n");

        $ingestor = new Ingestor(new PostgresDriver($this->pdo, chunkSize: 2), new CsvDriver());

        $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import()
            ->release();

        $customers = $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $addresses = $this->pdo->query('SELECT COUNT(*) FROM addresses')->fetchColumn();

        $this->assertSame(4, (int) $customers);
        $this->assertSame(4, (int) $addresses);
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS customers CASCADE');
        $this->pdo->exec('DROP TABLE IF EXISTS addresses CASCADE');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE customers (
    document TEXT PRIMARY KEY,
    name TEXT NOT NULL
)
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE addresses (
    document TEXT NOT NULL,
    city TEXT NOT NULL
)
SQL);
    }

    private function createCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingestor-csv-');
        $this->assertNotFalse($path);

        file_put_contents($path, $contents);

        return $path;
    }
}
