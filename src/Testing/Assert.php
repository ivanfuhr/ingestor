<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Testing;

use PHPUnit\Framework\Assert as PHPUnit;

final class Assert
{
    public static function true(bool $condition, string $message): void
    {
        PHPUnit::assertTrue($condition, $message);
    }

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        PHPUnit::assertSame($expected, $actual, $message);
    }

    public static function notNull(mixed $value, string $message): void
    {
        PHPUnit::assertNotNull($value, $message);
    }
}
