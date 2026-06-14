<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Dataset;

final readonly class InsertMutation
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $dataset,
        public array $data,
    ) {
    }
}
