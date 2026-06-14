<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Conflict;

use InvalidArgumentException;

final class ConflictColumns
{
    /**
     * @return non-empty-list<string>
     */
    public static function from(string ...$columns): array
    {
        if ($columns === []) {
            throw new InvalidArgumentException('At least one conflict column must be provided.');
        }

        return array_values($columns);
    }
}
