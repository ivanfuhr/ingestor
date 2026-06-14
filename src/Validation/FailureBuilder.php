<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Validation;

final class FailureBuilder
{
    private ?int $line = null;

    public function __construct(
        private readonly ?string $field,
        private readonly Severity $severity,
    ) {
    }

    public function onLine(int $line): self
    {
        $this->line = $line;

        return $this;
    }

    public function message(string $message): Failure
    {
        return new Failure($this->field, $message, $this->severity, $this->line);
    }
}
