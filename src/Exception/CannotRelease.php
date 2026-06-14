<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Exception;

use RuntimeException;

final class CannotRelease extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
