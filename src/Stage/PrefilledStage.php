<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Stage;

final class PrefilledStage implements StageStrategy
{
    public function __construct(
        private readonly bool $synchronizeSequences = true,
    ) {
    }

    public function synchronizeSequences(): bool
    {
        return $this->synchronizeSequences;
    }

    public static function withoutSequenceSync(): self
    {
        return new self(synchronizeSequences: false);
    }
}
