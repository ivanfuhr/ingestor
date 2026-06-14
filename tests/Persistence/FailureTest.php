<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Persistence;

use Ivanfuhr\Ingestor\Contract\Failure as FailureContract;
use Ivanfuhr\Ingestor\Persistence\Failure;
use Ivanfuhr\Ingestor\Validation\Severity;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PersistenceFailureTest extends TestCase
{
    #[Test]
    public function it_builds_failures_from_database_exceptions(): void
    {
        $cause = new PDOException('duplicate key value violates unique constraint');

        $failure = Failure::fromException(
            line: 1523,
            dataset: 'customers',
            data: ['name' => 'João', 'document' => null],
            cause: $cause,
        );

        $this->assertInstanceOf(FailureContract::class, $failure);
        $this->assertSame(1523, $failure->line());
        $this->assertNull($failure->field());
        $this->assertSame('customers', $failure->dataset());
        $this->assertSame(['name' => 'João', 'document' => null], $failure->data());
        $this->assertSame('duplicate key value violates unique constraint', $failure->message());
        $this->assertSame(Severity::ERROR, $failure->severity());
        $this->assertSame($cause, $failure->cause());
    }
}
