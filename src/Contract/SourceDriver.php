<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface SourceDriver
{
    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function read(mixed $source): iterable;
}
