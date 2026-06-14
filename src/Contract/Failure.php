<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Validation\Severity;
use Throwable;

interface Failure
{
    public function line(): ?int;

    public function field(): ?string;

    public function dataset(): ?string;

    /**
     * @return array<string, mixed>|null
     */
    public function data(): ?array;

    public function message(): string;

    public function severity(): Severity;

    public function cause(): ?Throwable;
}
