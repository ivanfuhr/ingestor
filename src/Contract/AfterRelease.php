<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface AfterRelease
{
    public function afterRelease(ReleasedImport $import): void;
}
