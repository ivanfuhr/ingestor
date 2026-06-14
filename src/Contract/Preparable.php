<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

interface Preparable
{
    public function prepare(Context $context): void;
}
