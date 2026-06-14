<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Stage\Stage;

interface PersistenceDriver
{
    public function begin(Definition $definition, Context $context): Stage;

    /**
     * @param iterable<RowContext> $rows
     *
     * @return list<Failure>
     */
    public function ingest(Stage $stage, iterable $rows): array;

    public function release(Stage $stage): void;

    public function rollback(Stage $stage): void;
}
