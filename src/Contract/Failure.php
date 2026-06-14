<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Validation\Severity;

interface Failure
{
    public function field(): ?string;

    public function message(): string;

    public function severity(): Severity;
}
