<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor;

use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Stage\Stage;

final readonly class ImportResult
{
    /**
     * @param list<Failure> $failures
     */
    public function __construct(
        private PersistenceDriver $persistence,
        private Stage $stage,
        private array $failures = [],
    ) {
    }

    /**
     * @return list<Failure>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /**
     * @return list<Failure>
     */
    public function errors(): array
    {
        return $this->failures;
    }

    public function release(): void
    {
        $this->persistence->release($this->stage);
    }

    public function rollback(): void
    {
        $this->persistence->rollback($this->stage);
    }

    public function stage(): Stage
    {
        return $this->stage;
    }
}
