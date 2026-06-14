<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\Dataset\Dataset;
use Ivanfuhr\Ingestor\Schema\Schema;

interface Definition
{
    public function schema(): Schema;

    /**
     * @param array<string, mixed> $row
     */
    public function map(array $row, Context $context): Dataset;
}
