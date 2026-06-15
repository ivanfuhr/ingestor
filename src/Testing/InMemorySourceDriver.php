<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Testing;

use Ivanfuhr\Ingestor\Context\ArrayRowContext;
use Ivanfuhr\Ingestor\Contract\SourceDriver;

final class InMemorySourceDriver implements SourceDriver
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private array $rows = [],
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function setRows(array $rows): void
    {
        $this->rows = $rows;
    }

    public function read(mixed $source): iterable
    {
        $line = 1;

        foreach ($this->rows as $row) {
            yield new ArrayRowContext($line++, $row);

            unset($row);
        }
    }
}
