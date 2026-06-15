---
name: ingestor
description: Guides development of the Ivanfuhr Ingestor PHP library — safe data imports with staging, definitions, drivers, validation, hooks, and testing. Use when working in this repository, implementing or reviewing Definitions, Source/Persistence drivers, Schema/Dataset mutations, import hooks, or Ingestor::test() utilities.
---

# Ingestor

PHP 8.2+ library for **safe, auditable data imports**: isolated staging, atomic release, extensible pipeline.

Namespace: `Ivanfuhr\Ingestor`. Package: `ivanfuhr/ingestor`.

## Architecture

```text
Source → SourceDriver → Iterable<RowContext>
  → Definition (prepare → validate → map) → Dataset (mutations)
  → PersistenceDriver → Stage (isolated) → release() (atomic promotion)
```

| Layer | Role | Built-ins |
|-------|------|-----------|
| **Source driver** | Read input into rows | `CsvDriver`, `XlsxDriver` |
| **Definition** | Schema + row → mutations | User implements `Definition` |
| **Persistence driver** | Stage, persist, promote | `PostgresDriver` |

Drivers are injected at construction — the pipeline never couples to SQL or file I/O directly.

```php
$ingestor = Ingestor::make(
    persistence: new PostgresDriver($pdo),
    source: new CsvDriver(),
);

$import = $ingestor->for(CustomerImport::class)->from($path)->import();

if ($import->hasFailures()) {
    $import->rollback();
    return;
}
$import->release();
```

## Definition checklist

When creating or modifying a Definition:

1. **`schema()`** — declare datasets, stage strategy, conflict strategy
2. **`map(Row $row, Context $context): Dataset`** — pure transformation; no I/O
3. Optional **`Preparable::prepare(Context)`** — preload caches, ID maps (I/O here)
4. Optional **`ValidatesRows::validate()`** — yield `Failure::error()` or `Failure::warning()`

```php
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
            ->insert('customers', ['document' => $row->string('cpf'), 'name' => $row->string('name')])
            ->insert('addresses', ['document' => $row->string('cpf'), 'city' => $row->string('city')]);
    }
}
```

### Stage strategies

| Class | Use when |
|-------|----------|
| `EmptyStage` | Dataset starts empty |
| `PrefilledStage` | Copy existing production data into stage (incremental updates) |

### Conflict strategies

`UpdateOnConflict`, `IgnoreOnConflict`, `ReplaceOnConflict`, `FailOnConflict` — all via `::by('column')`. Applied by persistence driver (`ON CONFLICT` in Postgres).

## Validation & failures

| Severity | Effect |
|----------|--------|
| `ERROR` | Row skipped — not mapped or persisted |
| `WARNING` | Recorded; row continues |

Persistence errors (NOT NULL, FK, UNIQUE) surface as the same `Failure` type with `line()`, `dataset()`, `data()`, `cause()`.

**Failures do not auto-rollback.** Caller chooses `release()` or `rollback()`.

`PostgresDriver` failure modes: `SqlFailureMode::Fast` (throughput) vs `SqlFailureMode::Diagnostic` (isolate failing row in batch).

## Lifecycle hooks

Fixed-count hooks (not per-row):

```text
beforeImport → prepare → validate/map/persist → afterImport
  → release → beforeRelease → promote → afterRelease
```

| Interface | Typical use |
|-----------|-------------|
| `BeforeImport` | Timers, audit start |
| `AfterImport` | Metrics, notifications |
| `BeforeRelease` | Final checks; throw `CannotRelease` to block |
| `AfterRelease` | Cache invalidation, external sync |

## Testing

Prefer `Ingestor::test()` — no database, CSV, or XLSX required.

```php
// Schema
Ingestor::test(CustomerImport::class)
    ->assertDataset('customers')
    ->assertStage(PrefilledStage::class)
    ->assertUpdateOnConflict('document');

// map()
Ingestor::test(CustomerImport::class)
    ->withContext(['customers' => ['123' => 1]])
    ->map(['cpf' => '123', 'name' => 'Ada', 'city' => 'SP'])
    ->assertInserted('customers', ['document' => '123', 'name' => 'Ada']);

// Full pipeline
Ingestor::test(CustomerImport::class)
    ->fromRows([...])
    ->import()
    ->assertRows(2)
    ->assertImportedRows(2)
    ->assertMutations(4);
```

In-memory drivers live in `src/Testing/` (`InMemoryPersistenceDriver`, `InMemorySourceDriver`).

## Code layout

| Path | Contents |
|------|----------|
| `src/Contract/` | Interfaces (`Definition`, drivers, hooks, `Context`, `Failure`) |
| `src/Dataset/` | `Dataset`, `InsertMutation` |
| `src/Row/` | `Row` — fluent row accessor for `map()` and `validate()` |
| `src/Schema/` | `Schema`, dataset builders |
| `src/Stage/` | `EmptyStage`, `PrefilledStage` |
| `src/Conflict/` | Conflict strategy value objects |
| `src/Driver/Source/` | `CsvDriver`, `XlsxDriver`, `XlsxSheet` |
| `src/Driver/Persistence/` | `PostgresDriver`, `SqlFailureMode` |
| `src/Testing/` | `DefinitionTest`, `Assert`, in-memory drivers |
| `src/Validation/` | `Failure`, `Severity` |

## Development commands

```bash
composer test       # PHPUnit
composer lint       # PHP-CS-Fixer (check)
composer lint:fix   # PHP-CS-Fixer (fix)
composer phpstan    # Static analysis
composer rector     # Automated refactoring
```

## Design principles

When implementing changes, preserve these invariants:

1. **`map()` is pure** — side effects and queries belong in `prepare()`
2. **Staging before production** — nothing reaches production until `release()`
3. **One row → zero or many mutations** across datasets via `Dataset::make()->insert(...)`
4. **Line numbers propagate** — source drivers yield `RowContext`; definitions receive `Row` with `line()` and fluent accessors
5. **Drivers are swappable** — new sources/persistence implement `SourceDriver` / `PersistenceDriver`

## Additional resources

- Full API examples and rationale: [reference.md](reference.md)
- User-facing docs: [README.md](../../../README.md)
