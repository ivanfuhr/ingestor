<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

use Ivanfuhr\Ingestor\Conflict\ConflictStrategy;
use Ivanfuhr\Ingestor\Contract\RowContext;

final class StagingInsertBuffer
{
    /**
     * @param list<string> $columns
     * @param list<array{context: RowContext, values: list<mixed>}> $rows
     */
    public function __construct(
        public readonly string $dataset,
        public readonly ?ConflictStrategy $conflict,
        public readonly array $columns,
        public array $rows = [],
        public int $count = 0,
    ) {
    }
}
