<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Schema;

use Ivanfuhr\Ingestor\Conflict\ConflictStrategy;
use Ivanfuhr\Ingestor\Stage\StageStrategy;

final readonly class DatasetConfig
{
    public function __construct(
        public StageStrategy $stageStrategy,
        public ?ConflictStrategy $conflictStrategy,
    ) {
    }
}
