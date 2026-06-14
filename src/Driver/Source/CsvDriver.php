<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Source;

use InvalidArgumentException;
use RuntimeException;
use Ivanfuhr\Ingestor\Contract\SourceDriver;

final class CsvDriver implements SourceDriver
{
    public function read(mixed $source): iterable
    {
        if (!is_string($source)) {
            throw new InvalidArgumentException('CSV source must be a file path string.');
        }

        if (!is_readable($source)) {
            throw new RuntimeException(sprintf('CSV file "%s" is not readable.', $source));
        }

        $handle = fopen($source, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open CSV file "%s".', $source));
        }

        try {
            $headers = fgetcsv($handle, length: 0, escape: '\\');

            if ($headers === false) {
                return;
            }

            if ($headers === [null]) {
                return;
            }

            while (($values = fgetcsv($handle, length: 0, escape: '\\')) !== false) {
                if ($values === [null]) {
                    continue;
                }

                yield $this->combine($headers, $values);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string|null> $headers
     * @param list<string|null> $values
     *
     * @return array<string, string|null>
     */
    private function combine(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === null) {
                continue;
            }

            if ($header === '') {
                continue;
            }

            $row[$header] = $values[$index] ?? null;
        }

        return $row;
    }
}
