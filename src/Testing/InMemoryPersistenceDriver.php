<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Testing;

use Ivanfuhr\Ingestor\Contract\Context;
use Ivanfuhr\Ingestor\Contract\Definition;
use Ivanfuhr\Ingestor\Contract\PersistenceDriver;
use Ivanfuhr\Ingestor\Metrics\MetricsRecorder;
use Ivanfuhr\Ingestor\Stage\Stage;

final class InMemoryPersistenceDriver implements PersistenceDriver
{
    public function begin(Definition $definition, Context $context): Stage
    {
        $stagingTables = [];

        foreach (array_keys($definition->schema()->datasets()) as $dataset) {
            $stagingTables[$dataset] = 'test_' . $dataset;
        }

        return new Stage('test', $definition, $stagingTables, $context);
    }

    public function ingest(Stage $stage, iterable $rows, MetricsRecorder $metrics): array
    {
        foreach ($rows as $rowContext) {
            $dataset = $stage->definition->map($rowContext->data(), $stage->context);

            foreach ($dataset->mutations() as $mutation) {
                $metrics->recordMutation($mutation->dataset);
                $metrics->recordPersisted($mutation->dataset);
            }
        }

        return [];
    }

    public function release(Stage $stage): void
    {
    }

    public function rollback(Stage $stage): void
    {
    }
}
