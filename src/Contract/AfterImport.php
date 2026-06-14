<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface AfterImport
{
    public function afterImport(ImportedImport $import): void;
}
