<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Stage\Stage;

interface ImportedImport
{
    /**
     * @return list<Failure>
     */
    public function failures(): array;

    public function hasFailures(): bool;

    public function metrics(): Metrics;

    public function stage(): Stage;

    public function context(): Context;
}
