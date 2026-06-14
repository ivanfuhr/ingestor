<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Conflict;

enum ConflictType
{
    case Update;
    case Ignore;
    case Replace;
    case Fail;
}

interface ConflictStrategy
{
    public function column(): string;

    public function type(): ConflictType;
}
