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
    /**
     * @return non-empty-list<string>
     */
    public function columns(): array;

    public function type(): ConflictType;
}
