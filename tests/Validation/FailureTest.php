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
}
