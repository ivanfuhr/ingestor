<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Validation;

use Ivanfuhr\Ingestor\Contract\Failure as FailureContract;
use Throwable;

final readonly class FailureWithLine implements FailureContract
{
    public function __construct(
        private FailureContract $failure,
        private int $line,
    ) {
    }

    public static function from(FailureContract $failure, int $line): FailureContract
    {
        if ($failure->line() !== null) {
            return $failure;
        }

        return new self($failure, $line);
    }

    public function line(): int
    {
        return $this->line;
    }

    public function field(): ?string
    {
        return $this->failure->field();
    }

    public function dataset(): ?string
    {
        return $this->failure->dataset();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        return $this->failure->data();
    }

    public function message(): string
    {
        return $this->failure->message();
    }

    public function severity(): Severity
    {
        return $this->failure->severity();
    }

    public function shouldSkipRow(): bool
    {
        return $this->failure->shouldSkipRow();
    }

    public function cause(): ?Throwable
    {
        return $this->failure->cause();
    }
}
