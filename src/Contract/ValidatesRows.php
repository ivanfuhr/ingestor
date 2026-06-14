<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface ValidatesRows
{
    /**
     * @param array<string, mixed> $row
     *
     * @return iterable<int, Failure>
     */
    public function validate(array $row, Context $context): iterable;
}
