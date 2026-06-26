<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Validation;

enum Severity
{
    case ERROR;

    case WARNING;

    public function skipsRowByDefault(): bool
    {
        return $this === self::ERROR;
    }
}
