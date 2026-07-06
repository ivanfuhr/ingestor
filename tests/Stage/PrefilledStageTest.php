<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Stage;

use Ivanfuhr\Ingestor\Stage\PrefilledStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrefilledStageTest extends TestCase
{
    #[Test]
    public function it_synchronizes_sequences_by_default(): void
    {
        $stage = new PrefilledStage();

        $this->assertTrue($stage->synchronizeSequences());
    }

    #[Test]
    public function it_can_disable_sequence_synchronization(): void
    {
        $stage = PrefilledStage::withoutSequenceSync();

        $this->assertFalse($stage->synchronizeSequences());
    }
}
