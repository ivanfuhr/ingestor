<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Contract;

use Ivanfuhr\Ingestor\ImportResult;

interface Ingestor
{
    /**
     * @param class-string<Definition> $definitionClass
     */
    public function for(string $definitionClass): self;

    public function from(mixed $source): self;

    public function import(): ImportResult;
}
