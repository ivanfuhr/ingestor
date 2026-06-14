<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface BeforeImport
{
    public function beforeImport(Context $context): void;
}
