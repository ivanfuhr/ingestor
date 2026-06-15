<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Source;

enum BomHandling
{
    case Keep;

    case Strip;
}
