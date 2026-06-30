<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Driver\Persistence;

use PDOException;
use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Conflict\DuplicateInBatch;
use Ivanfuhr\Ingestor\Conflict\IgnoreOnConflict;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Driver\Persistence\PostgresDriver;
use Ivanfuhr\Ingestor\Driver\Persistence\SqlFailureMode;
use Ivanfuhr\Ingestor\Driver\Source\CsvDriver;
use Ivanfuhr\Ingestor\Ingestor;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\DatasetBuilder;
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
    public function it_persists_boolean_false_values(): void
    {
        $this->pdo->exec('ALTER TABLE customers ADD COLUMN active BOOLEAN NOT NULL DEFAULT true');

        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                    'active' => $row->string('active') === '1',
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name,active
111,Ada,1
222,Bob,0
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertFalse($result->hasFailures());

        $result->release();

        $rows = $this->pdo->query('SELECT document, active FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['document' => '111', 'active' => true],
            ['document' => '222', 'active' => false],
        ], $rows);
    }

    #[Test]
    public function it_imports_csv_into_postgres_and_releases(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->onConflict(UpdateOnConflict::by('document'));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,Ada
222,Bob
CSV);

        $postgresDriver = new PostgresDriver($this->pdo);
        $ingestor = Ingestor::make($postgresDriver, new CsvDriver());

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
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(PrefilledStage::class)
                        ->onConflict(UpdateOnConflict::by('document'));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv("cpf,name\n111,New Name\n");

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import()
            ->release();

        $row = $this->pdo->query("SELECT name FROM customers WHERE document = '111'")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(['name' => 'New Name'], $row);
    }

    #[Test]
    public function it_preserves_surrogate_key_on_conflict_update(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS customer_records CASCADE');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE customer_records (
    id SERIAL PRIMARY KEY,
    customer_id TEXT NOT NULL UNIQUE,
    customer_state TEXT NOT NULL
)
SQL);
        $this->pdo->exec("INSERT INTO customer_records (customer_id, customer_state) VALUES ('06b8999e2fba1a1fbc88172c00ba8bc7', 'SP')");

        $existingId = (int) $this->pdo->query("SELECT id FROM customer_records WHERE customer_id = '06b8999e2fba1a1fbc88172c00ba8bc7'")->fetchColumn();

        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customer_records')
                        ->using(PrefilledStage::class)
                        ->onConflict(UpdateOnConflict::by('customer_id'));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customer_records', [
                    'customer_id' => $row->string('customer_id'),
                    'customer_state' => $row->string('customer_state'),
                ]);
            }
        };

        $csv = $this->createCsv("customer_id,customer_state\n06b8999e2fba1a1fbc88172c00ba8bc7,RS\n");

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import()
            ->release();

        $row = $this->pdo->query("SELECT id, customer_state FROM customer_records WHERE customer_id = '06b8999e2fba1a1fbc88172c00ba8bc7'")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($existingId, (int) $row['id']);
        $this->assertSame('RS', $row['customer_state']);

        $this->pdo->exec('DROP TABLE IF EXISTS customer_records CASCADE');
    }

    #[Test]
    public function it_updates_existing_rows_on_composite_conflict(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS customer_identities CASCADE');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE customer_identities (
    cpf TEXT NOT NULL,
    rg TEXT NOT NULL,
    name TEXT NOT NULL,
    PRIMARY KEY (cpf, rg)
)
SQL);
        $this->pdo->exec("INSERT INTO customer_identities (cpf, rg, name) VALUES ('111', 'AA', 'Old Name')");

        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customer_identities')
                        ->using(PrefilledStage::class)
                        ->onConflict(UpdateOnConflict::by('cpf', 'rg'));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customer_identities', [
                    'cpf' => $row->string('cpf'),
                    'rg' => $row->string('rg'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv("cpf,rg,name\n111,AA,New Name\n222,BB,Another\n");

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import()
            ->release();

        $rows = $this->pdo->query('SELECT cpf, rg, name FROM customer_identities ORDER BY cpf, rg')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['cpf' => '111', 'rg' => 'AA', 'name' => 'New Name'],
            ['cpf' => '222', 'rg' => 'BB', 'name' => 'Another'],
        ], $rows);

        $this->pdo->exec('DROP TABLE IF EXISTS customer_identities CASCADE');
    }

    #[Test]
    public function it_inserts_rows_in_chunks(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
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

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo, chunkSize: 2), new CsvDriver());

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
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit()
                    ->dataset('addresses')
                        ->using(EmptyStage::class)
                        ->commit();
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
        };

        $csvLines = ["cpf,name,city"];

        for ($index = 1; $index <= 4; ++$index) {
            $csvLines[] = sprintf('%d,Name %d,City %d', $index, $index, $index);
        }

        $csv = $this->createCsv(implode("\n", $csvLines) . "\n");

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo, chunkSize: 2), new CsvDriver());

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

    #[Test]
    public function it_collects_persistence_failures_in_diagnostic_mode(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf') === 'BAD' ? null : $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,Ada
BAD,Broken
333,Charlie
CSV);

        $ingestor = Ingestor::make(
            new PostgresDriver($this->pdo, chunkSize: 10, failureMode: SqlFailureMode::Diagnostic),
            new CsvDriver(),
        );

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertTrue($result->hasFailures());
        $this->assertCount(1, $result->failures());

        $failure = $result->failures()[0];
        $this->assertSame(3, $failure->line());
        $this->assertSame('customers', $failure->dataset());
        $this->assertSame(['cpf' => 'BAD', 'name' => 'Broken'], $failure->data());
        $this->assertStringContainsString('null value in column "document"', $failure->message());
        $this->assertInstanceOf(PDOException::class, $failure->cause());

        $result->release();

        $rows = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['document' => '111', 'name' => 'Ada'],
            ['document' => '333', 'name' => 'Charlie'],
        ], $rows);
    }

    #[Test]
    public function it_collects_persistence_failures_when_bad_row_falls_on_chunk_boundary(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf') === 'BAD' ? null : $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,Ada
222,Bob
333,Charlie
BAD,Broken
CSV);

        $ingestor = Ingestor::make(
            new PostgresDriver($this->pdo, chunkSize: 2, failureMode: SqlFailureMode::Diagnostic),
            new CsvDriver(),
        );

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertTrue($result->hasFailures());
        $this->assertCount(1, $result->failures());

        $failure = $result->failures()[0];
        $this->assertSame(5, $failure->line());
        $this->assertSame('customers', $failure->dataset());
        $this->assertSame(['cpf' => 'BAD', 'name' => 'Broken'], $failure->data());
        $this->assertStringContainsString('null value in column "document"', $failure->message());

        $result->release();

        $rows = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['document' => '111', 'name' => 'Ada'],
            ['document' => '222', 'name' => 'Bob'],
            ['document' => '333', 'name' => 'Charlie'],
        ], $rows);
    }

    #[Test]
    public function it_collects_batch_failures_in_fast_mode(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf') === 'BAD' ? null : $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,Ada
BAD,Broken
CSV);

        $ingestor = Ingestor::make(
            new PostgresDriver($this->pdo, chunkSize: 10, failureMode: SqlFailureMode::Fast),
            new CsvDriver(),
        );

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertTrue($result->hasFailures());
        $this->assertCount(1, $result->failures());

        $failure = $result->failures()[0];
        $this->assertNull($failure->line());
        $this->assertSame('customers', $failure->dataset());
        $this->assertNull($failure->data());
        $this->assertStringContainsString('null value in column "document"', $failure->message());
    }

    #[Test]
    public function it_replaces_production_contents_on_release(): void
    {
        $this->pdo->exec("INSERT INTO customers (document, name) VALUES ('999', 'Legacy'), ('111', 'Old Name')");

        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,Ada
222,Bob
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

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
    public function it_applies_on_conflict_when_writing_to_staging(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->onConflict(IgnoreOnConflict::by('document'));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,First
111,Duplicate
222,Bob
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertFalse($result->hasFailures());

        $result->release();

        $rows = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['document' => '111', 'name' => 'First'],
            ['document' => '222', 'name' => 'Bob'],
        ], $rows);
    }

    #[Test]
    public function it_updates_duplicate_rows_in_same_chunk_with_last_wins(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->onConflict(UpdateOnConflict::by('document'));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,First
111,Second
222,Bob
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertFalse($result->hasFailures());

        $result->release();

        $rows = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['document' => '111', 'name' => 'Second'],
            ['document' => '222', 'name' => 'Bob'],
        ], $rows);
    }

    #[Test]
    public function it_keeps_first_duplicate_when_configured(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->onConflict(UpdateOnConflict::by('document', DuplicateInBatch::FirstWins));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,First
111,Second
222,Bob
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertFalse($result->hasFailures());

        $result->release();

        $rows = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['document' => '111', 'name' => 'First'],
            ['document' => '222', 'name' => 'Bob'],
        ], $rows);
    }

    #[Test]
    public function it_fails_on_duplicate_conflict_keys_in_same_chunk(): void
    {
        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->onConflict(UpdateOnConflict::by('document', DuplicateInBatch::Fail));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,name
111,First
111,Second
222,Bob
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertTrue($result->hasFailures());
        $this->assertStringContainsString('Duplicate conflict key', $result->failures()[0]->message());

        $rows = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([], $rows);
    }

    #[Test]
    public function it_deduplicates_composite_conflict_keys_in_same_chunk(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS customer_identities CASCADE');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE customer_identities (
    cpf TEXT NOT NULL,
    rg TEXT NOT NULL,
    name TEXT NOT NULL,
    PRIMARY KEY (cpf, rg)
)
SQL);

        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customer_identities')
                        ->using(EmptyStage::class)
                        ->onConflict(UpdateOnConflict::by('cpf', 'rg'));
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customer_identities', [
                    'cpf' => $row->string('cpf'),
                    'rg' => $row->string('rg'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
cpf,rg,name
111,AA,First
111,AA,Second
111,BB,Other
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertFalse($result->hasFailures());

        $result->release();

        $rows = $this->pdo->query('SELECT cpf, rg, name FROM customer_identities ORDER BY cpf, rg')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['cpf' => '111', 'rg' => 'AA', 'name' => 'Second'],
            ['cpf' => '111', 'rg' => 'BB', 'name' => 'Other'],
        ], $rows);

        $this->pdo->exec('DROP TABLE IF EXISTS customer_identities CASCADE');
    }

    #[Test]
    public function it_omits_blank_identity_values_like_laravel(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS laravel_style_records CASCADE');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE laravel_style_records (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL
)
SQL);

        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('laravel_style_records')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('laravel_style_records', $row->toArray());
            }
        };

        $csv = $this->createCsv(<<<'CSV'
id,code,name
,alpha,First
,beta,Second
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertFalse($result->hasFailures());

        $result->release();

        $rows = $this->pdo->query('SELECT code, name FROM laravel_style_records ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['code' => 'alpha', 'name' => 'First'],
            ['code' => 'beta', 'name' => 'Second'],
        ], $rows);

        $this->pdo->exec('DROP TABLE IF EXISTS laravel_style_records CASCADE');
    }

    #[Test]
    public function it_inserts_explicit_ids_into_generated_always_identity_columns(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS identity_records CASCADE');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE identity_records (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL
)
SQL);

        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('identity_records')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('identity_records', [
                    'id' => (int) $row->string('id'),
                    'code' => $row->string('code'),
                    'label' => $row->string('label'),
                ]);
            }
        };

        $csv = $this->createCsv(<<<'CSV'
id,code,label
42,alpha,First
43,beta,Second
CSV);

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertFalse($result->hasFailures());

        $result->release();

        $rows = $this->pdo->query('SELECT id, code, label FROM identity_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['id' => 42, 'code' => 'alpha', 'label' => 'First'],
            ['id' => 43, 'code' => 'beta', 'label' => 'Second'],
        ], $rows);

        $this->pdo->exec('DROP TABLE IF EXISTS identity_records CASCADE');
    }

    #[Test]
    public function it_encodes_jsonb_arrays_and_objects_with_to_array(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS json_records CASCADE');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE json_records (
    code TEXT PRIMARY KEY,
    metadata JSONB NOT NULL,
    payload JSON NOT NULL
)
SQL);

        $payload = new class () {
            public function toArray(): array
            {
                return ['count' => 2, 'tags' => ['a', 'b']];
            }
        };

        $definition = new class ($payload) implements Definition {
            public function __construct(private readonly object $payload)
            {
            }

            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('json_records')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('json_records', [
                    'code' => $row->string('code'),
                    'metadata' => ['source' => $row->string('source')],
                    'payload' => $this->payload,
                ]);
            }
        };

        $csv = $this->createCsv("code,source\nitem-1,csv\n");

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $result = $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import();

        $this->assertFalse($result->hasFailures());

        $result->release();

        $row = $this->pdo->query("SELECT metadata, payload FROM json_records WHERE code = 'item-1'")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(['source' => 'csv'], json_decode((string) $row['metadata'], true));
        $this->assertSame(['count' => 2, 'tags' => ['a', 'b']], json_decode((string) $row['payload'], true));

        $this->pdo->exec('DROP TABLE IF EXISTS json_records CASCADE');
    }

    #[Test]
    public function it_replaces_production_when_table_is_referenced_by_foreign_keys(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE customer_notes (
    document TEXT NOT NULL REFERENCES customers (document)
)
SQL);
        $this->pdo->exec("INSERT INTO customers (document, name) VALUES ('111', 'Legacy')");
        $this->pdo->exec("INSERT INTO customer_notes (document) VALUES ('111')");

        $definition = new class () implements Definition {
            public function schema(): Schema|DatasetBuilder
            {
                return Schema::make()
                    ->dataset('customers')
                        ->using(EmptyStage::class)
                        ->commit();
            }

            public function map(Row $row, Context $context): Dataset
            {
                return Dataset::make()->insert('customers', [
                    'document' => $row->string('cpf'),
                    'name' => $row->string('name'),
                ]);
            }
        };

        $csv = $this->createCsv("cpf,name\n222,New Customer\n");

        $ingestor = Ingestor::make(new PostgresDriver($this->pdo), new CsvDriver());

        $ingestor
            ->for($definition::class)
            ->from($csv)
            ->import()
            ->release();

        $rows = $this->pdo->query('SELECT document, name FROM customers ORDER BY document')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['document' => '222', 'name' => 'New Customer'],
        ], $rows);
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS customer_notes CASCADE');
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
