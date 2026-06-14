<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface BeforeRelease
{
    public function beforeRelease(ImportedImport $import): void;
}
