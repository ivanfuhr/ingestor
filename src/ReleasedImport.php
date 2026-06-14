<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\Metrics;
use Ivanfuhr\Ingestor\Contract\ReleasedImport as ReleasedImportContract;
use Ivanfuhr\Ingestor\Stage\Stage;

final readonly class ReleasedImport implements ReleasedImportContract
{
    /**
     * @param list<Failure> $failures
     */
    public function __construct(
        private Stage $stage,
        private array $failures,
        private Metrics $metrics,
    ) {
    }

    /**
     * @return list<Failure>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function metrics(): Metrics
    {
        return $this->metrics;
    }

    public function stage(): Stage
    {
        return $this->stage;
    }

    public function context(): Context
    {
        return $this->stage->context;
    }
}
