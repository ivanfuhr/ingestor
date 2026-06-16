# Ingestor Reference

Quick lookup for agents working in this codebase. See [README.md](../../../README.md) for full documentation.

## Import result API

```php
$import = $ingestor->for(MyImport::class)->from($source)->import();

$import->hasFailures();
$import->failures();   // iterable<Failure>
$import->metrics();    // available after import, release or rollback

$import->release();    // atomic promotion
$import->rollback();   // discard stage
```

### Metrics

```php
$m = $import->metrics();
$m->startedAt(); $m->finishedAt(); $m->duration();
$m->rows(); $m->importedRows(); $m->failedRows(); $m->mutations();

foreach ($m->datasets() as $ds) {
    $ds->name();
    $ds->stageStrategy();     // FQCN, e.g. PrefilledStage::class
    $ds->onConflict();        // ConflictType or null
    $ds->onConflictColumns(); // e.g. ['document']
    $ds->mutations(); $ds->persisted(); $ds->failures();
}
```

All datasets declared in the schema appear in metrics, even when they produced no mutations during the import.

## Validation

```php
use Ivanfuhr\Ingestor\Contract\ValidatesRows;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Validation\Failure;

public function validate(Row $row, Context $context): iterable
{
    if ($row->missing('document')) {
        yield Failure::error('document')->message('Document is required.');
    }
    if ($row->missing('phone')) {
        yield Failure::warning('phone')->message('Phone number is empty.');
    }
}
```

## Context / Preparable

```php
use Ivanfuhr\Ingestor\Contract\Preparable;

public function prepare(Context $context): void
{
    $context->put('customers', Customer::pluck('id', 'document')->all());
}

public function map(Row $row, Context $context): Dataset
{
    // $row->line(), $row->string('document'), $context->get('customers', $row->string('document')), etc.
}
```

## BeforeRelease guard

```php
use Ivanfuhr\Ingestor\Exception\CannotRelease;

public function beforeRelease(ImportedImport $import): void
{
    if ($import->hasFailures()) {
        throw CannotRelease::because('Import contains unresolved failures.');
    }
}
```

## XlsxDriver

```php
use Ivanfuhr\Ingestor\Driver\Source\XlsxDriver;
use Ivanfuhr\Ingestor\Driver\Source\XlsxSheet;

// First worksheet (default)
new XlsxDriver();

// By name or zero-based index
new XlsxDriver(XlsxSheet::byName('Orders'));
new XlsxDriver(XlsxSheet::byIndex(1));
```

- Zero Composer dependencies — uses `ZipArchive` + `XMLReader` only
- Header row → associative `RowContext` data; Excel row numbers for failures
- Streams sheet XML incrementally via `zip://` (one row in memory at a time)
- Shared strings loaded into an index (sheet data itself stays streamed)
- Excel serial dates returned as raw numbers; formulas read cached `<v>` values

## PostgresDriver

```php
new PostgresDriver(
    pdo: $pdo,
    chunkSize: 500,
    failureMode: SqlFailureMode::Fast,      // or Diagnostic
);
```

- Introspects production tables to build matching staging tables
- Applies Schema conflict strategies via `ON CONFLICT`
- Deduplicates duplicate conflict keys within each insert batch for `UpdateOnConflict` / `ReplaceOnConflict` (default `DuplicateInBatch::LastWins`)
- Staging + atomic swap enables safe rollback window

## Testing assertions

| Method | Purpose |
|--------|---------|
| `assertDataset(name)` | Dataset declared in schema |
| `assertStage(class)` | Stage strategy class |
| `assertUpdateOnConflict(col)` | Conflict strategy |
| `assertInserted(dataset, data)` | Insert mutation in map result |
| `assertDatasetCount(name, n)` | Mutation count per dataset |
| `assertFailure(field:, message:)` | Validation failure |
| `assertFailureCount(n)` | Total failures |
| `assertRows(n)` | Rows processed |
| `assertImportedRows(n)` | Successful rows |
| `assertFailedRows(n)` | Failed rows |
| `assertMutations(n)` | Total mutations |

## Key contracts

| Interface | Method(s) |
|-----------|-----------|
| `Definition` | `schema()`, `map(Row, Context)` |
| `Preparable` | `prepare(Context)` |
| `ValidatesRows` | `validate(Row, Context)` → iterable failures |
| `SourceDriver` | `read(mixed): iterable<RowContext>` |
| `PersistenceDriver` | stage lifecycle, persist, release, rollback |
| `BeforeImport` / `AfterImport` | import boundaries |
| `BeforeRelease` / `AfterRelease` | release boundaries |

## Failure inspection

```php
foreach ($import->failures() as $failure) {
    $failure->line();     // source line
    $failure->dataset();  // affected dataset
    $failure->message();
    $failure->data();     // row data
    $failure->cause();    // underlying exception (persistence)
}
```
