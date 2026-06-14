<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface SourceDriver
{
    /**
     * @return iterable<RowContext>
     */
    public function read(mixed $source): iterable;
}
