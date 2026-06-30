<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

final class PostgresIdentifier
{
    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function quoteQualified(string $qualifiedName): string
    {
        $parts = explode('.', str_replace('"', '', $qualifiedName));

        return implode('.', array_map($this->quote(...), $parts));
    }

    public function stagingTableName(string $stageId, string $dataset): string
    {
        return sprintf('ingestor_stage_%s_%s', $stageId, $dataset);
    }

    public function generateStageId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function basename(string $table): string
    {
        return basename(str_replace('"', '', $table));
    }
}
