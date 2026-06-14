<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Stage;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;

final readonly class Stage
{
    /**
     * @param array<string, string> $stagingTables dataset name => staging table name
     */
    public function __construct(
        public string $id,
        public Definition $definition,
        public array $stagingTables,
        public Context $context,
    ) {
    }

    public function stagingTable(string $dataset): string
    {
        if (!isset($this->stagingTables[$dataset])) {
            throw new InvalidArgumentException(sprintf('Unknown dataset "%s" in stage.', $dataset));
        }

        return $this->stagingTables[$dataset];
    }
}
