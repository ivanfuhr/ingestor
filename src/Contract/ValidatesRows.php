<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Row\Row;

interface ValidatesRows
{
    /**
     * @return iterable<int, Failure>
     */
    public function validate(Row $row, Context $context): iterable;
}
