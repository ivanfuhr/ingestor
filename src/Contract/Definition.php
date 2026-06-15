<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Schema\Schema;

interface Definition
{
    public function schema(): Schema;

    public function map(Row $row, Context $context): Dataset;
}
