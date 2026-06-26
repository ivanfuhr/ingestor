<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Validation;

final class FailureBuilder
{
    private ?int $line = null;

    private ?bool $skipRow = null;

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

    public function skipRow(): self
    {
        $this->skipRow = true;

        return $this;
    }

    public function continueRow(): self
    {
        $this->skipRow = false;

        return $this;
    }

    public function message(string $message): Failure
    {
        return new Failure($this->field, $message, $this->severity, $this->line, $this->skipRow);
    }
}
