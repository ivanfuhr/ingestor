<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Persistence\Postgres;

final class PostgresIdentifier
{
    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
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
