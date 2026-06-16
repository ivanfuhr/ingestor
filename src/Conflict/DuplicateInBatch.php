<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Conflict;

enum DuplicateInBatch
{
    case LastWins;
    case FirstWins;
    case Fail;
}
