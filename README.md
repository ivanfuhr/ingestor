<a href="https://github.com/ivanfuhr/ingestor">
  <img alt="Ingestor" src="art/header.png">
</a>

# Ingestor

<p>
    <a href="https://packagist.org/packages/ivanfuhr/ingestor"><img src="https://img.shields.io/packagist/dt/ivanfuhr/ingestor" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/ivanfuhr/ingestor"><img src="https://img.shields.io/packagist/v/ivanfuhr/ingestor" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/ivanfuhr/ingestor"><img src="https://img.shields.io/packagist/l/ivanfuhr/ingestor" alt="License"></a>
</p>

Ingestor is a PHP library for **safe, auditable data imports** with isolated staging, atomic release, and an extensible pipeline.

Data enters through a source driver, is transformed into mutations by a definition, is applied in an isolated stage by a persistence driver, and is only then promoted to production — safely and atomically.

> **Requires [PHP 8.2+](https://php.net/releases/)**, the **PDO** extension (persistence), and the **zip** and **xml** extensions (XLSX source).

## Installation

⚡️ Get started by requiring the package using [Composer](https://getcomposer.org):

```bash
composer require ivanfuhr/ingestor
```

## Quick Start

```php
use Ivanfuhr\Ingestor\Ingestor;
use Ivanfuhr\Ingestor\Driver\Persistence\PostgresDriver;
use Ivanfuhr\Ingestor\Driver\Source\CsvDriver;

$ingestor = Ingestor::make(
    persistence: new PostgresDriver($pdo),
    source: new CsvDriver(),
);

$import = $ingestor
    ->for(CustomerImport::class)
    ->from('/path/to/customers.csv')
    ->import();

if ($import->hasFailures()) {
    foreach ($import->failures() as $failure) {
        // inspect validation or persistence failures
    }

    $import->rollback();

    return;
}

$import->release();
```

## Table of Contents

- [Architecture](#-architecture)
- [Definitions & Schema](#-definitions--schema)
- [Context](#-context)
- [Validation](#-validation)
- [Persistence Failures](#-persistence-failures)
- [Hooks](#-hooks)
- [Metrics](#-metrics)
- [Testing Utilities](#-testing-utilities)
- [PostgreSQL Driver](#-postgresql-driver)
- [CSV Driver](#-csv-driver)
- [XLSX Driver](#-xlsx-driver)
- [Development](#-development)
- [Community](#community)
- [License](#license)

### 🏗️ Architecture

Ingestor separates four responsibilities:

```text
Source
    ↓
Source Driver
    ↓
Iterable<RowContext>
    ↓
Definition (prepare → validate → map)
    ↓
Dataset (mutations)
    ↓
Persistence Driver
    ↓
Stage (isolated)
    ↓
Release (atomic promotion)
```

| Driver | Responsibility | Implementations |
|--------|----------------|-----------------|
| **Source** | Turns a source into input rows | `CsvDriver`, `XlsxDriver` |
| **Persistence** | Creates staging, persists mutations, and releases | `PostgresDriver` |

Drivers are injected at construction time. The import pipeline never needs to know how data is read or written.

```php
$ingestor = Ingestor::make(
    persistence: new PostgresDriver($pdo),
    source: new CsvDriver(),
);
```

**Why:** Keeps reading, transformation, and persistence independent — each piece can be swapped or tested in isolation.

---

### 📋 Definitions & Schema

A **Definition** describes an import. It declares structure via `Schema` and transforms each row into write intentions via `Dataset`.

```php
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\Schema;
use Ivanfuhr\Ingestor\Stage\EmptyStage;
use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;

final class CustomerImport implements Definition
{
    public function schema(): Schema
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
```

#### Stage Strategies

| Strategy | Behavior |
|----------|----------|
| `EmptyStage` | Dataset starts empty |
| `PrefilledStage` | Dataset starts with a copy of existing data (ideal for incremental updates) |

#### Conflict Strategies

Declared in the Schema and translated by the persistence driver:

```php
UpdateOnConflict::by('document');
UpdateOnConflict::by('document', DuplicateInBatch::FirstWins);
IgnoreOnConflict::by('document');
ReplaceOnConflict::by('document');
FailOnConflict::by('document');
```

`UpdateOnConflict` and `ReplaceOnConflict` deduplicate rows that share the same conflict key within a single insert batch before executing `ON CONFLICT DO UPDATE`. By default, the **last row wins** (`DuplicateInBatch::LastWins`). This prevents PostgreSQL error `ON CONFLICT DO UPDATE command cannot affect row a second time`, which occurs when duplicate keys appear in the same multi-row `INSERT` — common with `PrefilledStage` incremental imports, but not caused by the stage strategy itself.

| `DuplicateInBatch` | Behavior |
|--------------------|----------|
| `LastWins` (default) | Keep the last occurrence of each conflict key in the batch |
| `FirstWins` | Keep the first occurrence |
| `Fail` | Abort the batch and report failures for duplicate keys |

A **Stage** is an isolated ingestion environment. Nothing touches production until `release()` is called.

```text
Import
└── Stage
    ├── customers (staging table)
    └── addresses (staging table)
```

**Why:** One row can produce zero, one, or many mutations across multiple datasets — without coupling business logic to SQL.

---

### 🗂️ Context

Shared storage available throughout an import. Use it to preload ID maps, caches, and reference data so `map()` stays pure and fast.

```php
use Ivanfuhr\Ingestor\Contract\Preparable;

final class OrderImport implements Definition, Preparable
{
    public function prepare(Context $context): void
    {
        $context->put('customers', Customer::pluck('id', 'document')->all());
    }

    public function map(Row $row, Context $context): Dataset
    {
        return Dataset::make()->insert('orders', [
            'customer_id' => $context->get('customers', $row->string('document')),
            'total' => $row->float('total'),
        ]);
    }
}
```

**Why:** Avoids N+1 queries during import. I/O belongs in `prepare()`; `map()` should be a pure `Row + Context → Dataset` transformation.

---

### ✅ Validation

Row validation is optional and runs before mapping. Implement `ValidatesRows` on your definition:

```php
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Validation\Failure;

final class CustomerImport implements Definition, ValidatesRows
{
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
    }
}
```

| Severity | Behavior |
|----------|----------|
| `ERROR` | Row is skipped — not mapped or persisted |
| `WARNING` | Recorded, but the row continues through the pipeline |

Failures are available after import:

```php
$import->failures();
$import->hasFailures();
```

**Why:** Invalid rows are caught early, before any database writes, with full reporting for audits and reprocessing.

---

### 🚨 Persistence Failures

Database errors (NOT NULL, FOREIGN KEY, UNIQUE, etc.) are exposed through the same `Failure` mechanism, with additional context:

- `line()` — original source line number
- `dataset()` — affected dataset
- `data()` — row data
- `cause()` — underlying exception

Failures **do not** trigger an automatic rollback. You decide between `release()` and `rollback()`.

```php
$import = $ingestor
    ->for(CustomerImport::class)
    ->from($file)
    ->import();

if ($import->hasFailures()) {
    foreach ($import->failures() as $failure) {
        dump([
            'line' => $failure->line(),
            'dataset' => $failure->dataset(),
            'message' => $failure->message(),
            'data' => $failure->data(),
        ]);
    }

    $import->rollback();
    return;
}

$import->release();
```

#### SQL Failure Modes

`PostgresDriver` supports configurable failure diagnosis:

```php
use Ivanfuhr\Ingestor\Driver\Persistence\SqlFailureMode;

new PostgresDriver($pdo, chunkSize: 500, failureMode: SqlFailureMode::Diagnostic);
```

| Mode | Priority |
|------|----------|
| `Fast` | Throughput — records batch failure when a bulk INSERT fails |
| `Diagnostic` | Traceability — subdivides the batch to isolate the failing row |

**Why:** Every mutation inherits its source row context, so persistence errors remain traceable even at scale.

---

### 🔗 Hooks

High-level lifecycle hooks for auditing, metrics, notifications, and external integrations. They run a fixed number of times regardless of row volume.

```text
beforeImport()
    ↓
prepare()
    ↓
validate() → map() → persist()
    ↓
afterImport()
    ↓
release()
    ↓
beforeRelease() → promote stage → afterRelease()
```

| Interface | When | Typical use |
|-----------|------|-------------|
| `BeforeImport` | Before import starts | Timers, logging, audit trail |
| `AfterImport` | After all rows processed, before release | Metrics, reports, notifications |
| `BeforeRelease` | Immediately before promotion | Final checks, manual approval |
| `AfterRelease` | After promotion | Cache invalidation, external sync |

`BeforeRelease` can block publication:

```php
use Ivanfuhr\Ingestor\Exception\CannotRelease;

public function beforeRelease(ImportedImport $import): void
{
    if ($import->hasFailures()) {
        throw CannotRelease::because('Import contains unresolved failures.');
    }
}
```

**Why:** Integrate with the outside world without per-row callbacks that would destroy throughput.

---

### 📊 Metrics

Read-only metrics collected during import. Available whether you release or rollback. Every dataset declared in the schema is included in the per-dataset breakdown, even when it produced no mutations.

```php
$metrics = $import->metrics();

$metrics->startedAt();
$metrics->finishedAt();
$metrics->duration();

$metrics->rows();          // rows processed
$metrics->importedRows();  // rows imported successfully
$metrics->failedRows();    // rows with failures
$metrics->mutations();     // mutations produced

foreach ($metrics->datasets() as $dataset) {
    $dataset->name();
    $dataset->stageStrategy();     // e.g. PrefilledStage::class
    $dataset->onConflict();        // ConflictType or null
    $dataset->onConflictColumns(); // e.g. ['document']
    $dataset->mutations();
    $dataset->persisted();
    $dataset->failures();
}
```

Failures answer *what* and *why*. Metrics answer *how much*, *how long*, and *how each dataset was configured*.

**Why:** Every import becomes observable — performance, throughput, per-dataset breakdowns, and schema configuration (staging strategy and conflict handling) without affecting the pipeline.

---

### 🧪 Testing Utilities

Test definitions in isolation — no database, no CSV or XLSX files, no external infrastructure.

#### Asserting the Schema

```php
use Ivanfuhr\Ingestor\Ingestor;

Ingestor::test(CustomerImport::class)
    ->assertDataset('customers')
    ->assertStage(PrefilledStage::class)
    ->assertUpdateOnConflict('document');
```

#### Asserting `map()`

```php
Ingestor::test(CustomerImport::class)
    ->withContext(['customers' => ['12345678901' => 1]])
    ->map(['cpf' => '12345678901', 'name' => 'Ada', 'city' => 'SP'])
    ->assertInserted('customers', [
        'document' => '12345678901',
        'name' => 'Ada',
    ])
    ->assertDatasetCount('addresses', 1);
```

#### Asserting Validation

```php
Ingestor::test(CustomerImport::class)
    ->map(['document' => null])
    ->assertFailure(field: 'document', message: 'Document is required.')
    ->assertFailureCount(1);
```

#### Asserting the Full Pipeline

```php
Ingestor::test(CustomerImport::class)
    ->fromRows([
        ['cpf' => '1', 'name' => 'Ada', 'city' => 'SP'],
        ['cpf' => '2', 'name' => 'Bob', 'city' => 'RJ'],
    ])
    ->import()
    ->assertRows(2)
    ->assertImportedRows(2)
    ->assertFailedRows(0)
    ->assertMutations(4);
```

**Why:** Definitions should be fully testable with fast, deterministic tests — safe to refactor without spinning up infrastructure.

---

### 🐘 PostgreSQL Driver

`PostgresDriver` creates staging tables, inserts data in configurable batches, and atomically promotes staging to production.

```php
use Ivanfuhr\Ingestor\Driver\Persistence\PostgresDriver;
use Ivanfuhr\Ingestor\Driver\Persistence\SqlFailureMode;

$driver = new PostgresDriver(
    pdo: $pdo,
    chunkSize: 500,
    failureMode: SqlFailureMode::Fast,
);
```

The driver introspects production tables to build matching staging tables and applies conflict strategies from the Schema via `ON CONFLICT`.

**Why:** Staging + atomic swap gives you a safe rollback window before data ever reaches production.

---

### 📄 CSV Driver

`CsvDriver` reads CSV files with a header row and yields `RowContext` objects with line numbers and associative data.

```php
use Ivanfuhr\Ingestor\Driver\Source\CsvDriver;

$ingestor = Ingestor::make($persistence, new CsvDriver());
```

**Why:** Line numbers flow through the entire pipeline, enabling precise failure reporting back to the source file.

---

### 📊 XLSX Driver

`XlsxDriver` reads Excel `.xlsx` files with a header row and yields `RowContext` objects with line numbers and associative data — same API as `CsvDriver`.

It has **zero Composer dependencies**: the file is opened as a ZIP archive and parsed incrementally with `XMLReader`, keeping memory use low for large sheets.

```php
use Ivanfuhr\Ingestor\Driver\Source\XlsxDriver;
use Ivanfuhr\Ingestor\Driver\Source\XlsxSheet;

// First worksheet (default)
$ingestor = Ingestor::make($persistence, new XlsxDriver());

$ingestor
    ->for(CustomerImport::class)
    ->from('/path/to/customers.xlsx')
    ->import();

// Select a worksheet by name or zero-based index
new XlsxDriver(XlsxSheet::byName('Orders'));
new XlsxDriver(XlsxSheet::byIndex(1));
```

| Feature | Support |
|---------|---------|
| Shared strings | Yes |
| Inline strings | Yes |
| Booleans and formula cached values | Yes |
| Excel serial dates | Returned as raw numbers |
| Multiple sheets | One sheet per driver instance |

**Why:** Spreadsheet imports get the same DX and traceability as CSV — header-based associative rows and Excel row numbers for failure reporting — without pulling in PhpSpreadsheet or similar.

---

### 🛠️ Development

```bash
composer test          # PHPUnit
composer lint          # PHP-CS-Fixer (check)
composer lint:fix      # PHP-CS-Fixer (fix)
composer phpstan       # Static analysis
composer rector        # Automated refactoring
```

## Community

- [Contributing](CONTRIBUTING.md)
- [Security Policy](SECURITY.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](CHANGELOG.md)

## License

**Ingestor** was created by **[Ivan Führ](https://github.com/ivanfuhr)** under the **[MIT license](LICENSE)**.
