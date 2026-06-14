<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence;

enum SqlFailureMode
{
    case Fast;

    case Diagnostic;
}
