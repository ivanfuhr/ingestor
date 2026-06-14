<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Dataset;

final class Dataset
{
    /** @var list<InsertMutation> */
    private array $mutations = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $dataset, array $data): self
    {
        $this->mutations[] = new InsertMutation($dataset, $data);

        return $this;
    }

    /**
     * @return list<InsertMutation>
     */
    public function mutations(): array
    {
        return $this->mutations;
    }
}
