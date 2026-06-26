<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Validation;

use Ivanfuhr\Ingestor\Contract\Failure as FailureContract;
use Ivanfuhr\Ingestor\Validation\Failure;
use Ivanfuhr\Ingestor\Validation\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FailureTest extends TestCase
{
    #[Test]
    public function it_builds_error_failures(): void
    {
        $failure = Failure::error('document')
            ->message('Document is required.');

        $this->assertInstanceOf(FailureContract::class, $failure);
        $this->assertSame('document', $failure->field());
        $this->assertSame('Document is required.', $failure->message());
        $this->assertSame(Severity::ERROR, $failure->severity());
    }

    #[Test]
    public function it_builds_warning_failures(): void
    {
        $failure = Failure::warning('phone')
            ->message('Phone number is empty.');

        $this->assertSame('phone', $failure->field());
        $this->assertSame('Phone number is empty.', $failure->message());
        $this->assertSame(Severity::WARNING, $failure->severity());
    }

    #[Test]
    public function it_allows_null_fields(): void
    {
        $failure = Failure::error()
            ->message('Row is invalid.');

        $this->assertNull($failure->field());
    }

    #[Test]
    public function it_skips_rows_by_default_based_on_severity(): void
    {
        $error = Failure::error('document')->message('Required.');
        $warning = Failure::warning('phone')->message('Empty.');

        $this->assertTrue($error->shouldSkipRow());
        $this->assertFalse($warning->shouldSkipRow());
    }

    #[Test]
    public function it_can_override_row_skipping(): void
    {
        $warningThatSkips = Failure::warning('phone')
            ->skipRow()
            ->message('Phone is empty but required for this import.');

        $errorThatContinues = Failure::error('phone')
            ->continueRow()
            ->message('Phone is invalid but row can still be imported.');

        $this->assertTrue($warningThatSkips->shouldSkipRow());
        $this->assertFalse($errorThatContinues->shouldSkipRow());
    }
}
