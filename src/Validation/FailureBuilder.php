<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Validation;

final class FailureBuilder
{
    public function __construct(
        private readonly ?string $field,
        private readonly Severity $severity,
    ) {
    }

    public function message(string $message): Failure
    {
        return new Failure($this->field, $message, $this->severity);
    }
}
