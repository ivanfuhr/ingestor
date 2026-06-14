<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Stage\Stage;

interface ReleasedImport
{
    /**
     * @return list<Failure>
     */
    public function failures(): array;

    public function metrics(): Metrics;

    public function stage(): Stage;

    public function context(): Context;
}
