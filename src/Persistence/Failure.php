<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Persistence;

use Ivanfuhr\Ingestor\Contract\Failure as FailureContract;
use Ivanfuhr\Ingestor\Validation\Severity;
use Throwable;

final readonly class Failure implements FailureContract
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        private ?int $line,
        private ?string $dataset,
        private ?array $data,
        private string $message,
        private Severity $severity,
        private ?Throwable $cause = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromException(
        ?int $line,
        ?string $dataset,
        ?array $data,
        Throwable $cause,
    ): self {
        return new self($line, $dataset, $data, $cause->getMessage(), Severity::ERROR, $cause);
    }

    /**
     * @param array<string, mixed>|null $data
     * @param non-empty-list<string> $conflictColumns
     * @param non-empty-list<int> $lines
     */
    public static function duplicateConflictKeyInBatch(
        int $line,
        string $dataset,
        ?array $data,
        array $conflictColumns,
        array $lines,
    ): self {
        sort($lines);

        $message = sprintf(
            'Duplicate conflict key (%s) in the same insert batch on lines %s',
            implode(', ', $conflictColumns),
            implode(', ', array_map(strval(...), $lines)),
        );

        return new self($line, $dataset, $data, $message, Severity::ERROR);
    }

    public function line(): ?int
    {
        return $this->line;
    }

    public function field(): ?string
    {
        return null;
    }

    public function dataset(): ?string
    {
        return $this->dataset;
    }

    public function data(): ?array
    {
        return $this->data;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function severity(): Severity
    {
        return $this->severity;
    }

    public function cause(): ?Throwable
    {
        return $this->cause;
    }
}
