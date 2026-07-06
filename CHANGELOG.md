# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `PrefilledStage::withoutSequenceSync()` to opt out of staging sequence synchronization when every insert provides an explicit surrogate key
- `DatasetBuilder::using()` now accepts `StageStrategy` instances in addition to class names (e.g. `->using(PrefilledStage::withoutSequenceSync())`)
- `ignoreEmptyRows` option on `CsvDriver` and `XlsxDriver` to skip rows where every field is blank (`null`, `''`, or whitespace)
- `DuplicateInBatch` enum for handling duplicate conflict keys within the same insert batch (`LastWins`, `FirstWins`, `Fail`)
- Deduplication in `PostgresDriver` for `UpdateOnConflict` and `ReplaceOnConflict` to prevent PostgreSQL cardinality violations
- Source driver: `CsvDriver`
- Source driver: `XlsxDriver` — zero Composer dependencies; streams sheet XML via `XMLReader` and `zip://`; worksheet selection via `XlsxSheet::byName()` / `byIndex()`
- PHP extensions: `ext-xml` and `ext-zip` (required for XLSX)
- Persistence driver: `PostgresDriver` with staging, batch inserts, and atomic release
- Context and `Preparable` for preloading reference data
- Row validation via `ValidatesRows` with `ERROR` and `WARNING` severities
- Per-failure row skipping overrides via `Failure::skipRow()` and `Failure::continueRow()`
- Persistence failure diagnostics with `Fast` and `Diagnostic` SQL modes
- Lifecycle hooks: `BeforeImport`, `AfterImport`, `BeforeRelease`, `AfterRelease`
- Import metrics: duration, row counts, mutations, and per-dataset breakdown
- Per-dataset metrics metadata: stage strategy (`EmptyStage`, `PrefilledStage`) and `onConflict` configuration (type and columns)
- Testing utilities via `Ingestor::test()` with fluent assertions
- Conflict strategies: `UpdateOnConflict`, `IgnoreOnConflict`, `ReplaceOnConflict`, `FailOnConflict`
- Stage strategies: `EmptyStage`, `PrefilledStage`

### Fixed

- `PrefilledStage` with `PostgresDriver` now synchronizes serial/identity sequences on staging tables after copying production data, preventing duplicate primary key violations when new rows omit the surrogate key
