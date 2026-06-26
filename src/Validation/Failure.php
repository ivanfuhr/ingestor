<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Validation;

use Ivanfuhr\Ingestor\Contract\Failure as FailureContract;

final class Failure implements FailureContract
{
    public function __construct(
        private readonly ?string $field,
        private readonly string $message,
        private readonly Severity $severity,
        private readonly ?int $line = null,
        private readonly ?bool $skipRow = null,
    ) {
    }

    public static function error(?string $field = null): FailureBuilder
    {
        return new FailureBuilder($field, Severity::ERROR);
    }

    public static function warning(?string $field = null): FailureBuilder
    {
        return new FailureBuilder($field, Severity::WARNING);
    }

    public function line(): ?int
    {
        return $this->line;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function dataset(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        return null;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function severity(): Severity
    {
        return $this->severity;
    }

    public function shouldSkipRow(): bool
    {
        return $this->skipRow ?? $this->severity->skipsRowByDefault();
    }

    public function cause(): ?\Throwable
    {
        return null;
    }
}
