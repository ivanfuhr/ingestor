<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor;

use Ivanfuhr\Ingestor\Contract\AfterRelease;
use Ivanfuhr\Ingestor\Contract\BeforeRelease;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Failure;
use Ivanfuhr\Ingestor\Contract\ImportedImport;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Stage\Stage;

final readonly class ImportResult implements ImportedImport
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

    public function context(): Context
    {
        return $this->stage->context;
    }

    public function release(): void
    {
        $definition = $this->stage->definition;

        if ($definition instanceof BeforeRelease) {
            $definition->beforeRelease($this);
        }

        $this->persistence->release($this->stage);

        if ($definition instanceof AfterRelease) {
            $definition->afterRelease(new ReleasedImport($this->stage, $this->failures));
        }
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
