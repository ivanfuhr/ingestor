<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Source;

use InvalidArgumentException;
use RuntimeException;
use Ivanfuhr\Ingestor\Context\ArrayRowContext;
use Ivanfuhr\Ingestor\Contract\SourceDriver;

final class CsvDriver implements SourceDriver
{
    private readonly SourceEncoding $encoding;

    public function __construct(
        ?SourceEncoding $encoding = null,
    ) {
        $this->encoding = $encoding ?? SourceEncoding::utf8();
    }

    public function read(mixed $source): iterable
    {
        if (!is_string($source)) {
            throw new InvalidArgumentException('CSV source must be a file path string.');
        }

        if (!is_readable($source)) {
            throw new RuntimeException(sprintf('CSV file "%s" is not readable.', $source));
        }

        $handle = $this->openStream($source);

        try {
            $lineNumber = 1;
            $headers = fgetcsv($handle, length: 0, escape: '\\');

            if ($headers === false) {
                return;
            }

            if ($headers === [null]) {
                return;
            }

            $headers = $this->normalizeHeaders($headers);

            while (($values = fgetcsv($handle, length: 0, escape: '\\')) !== false) {
                ++$lineNumber;

                if ($values === [null]) {
                    continue;
                }

                yield new ArrayRowContext($lineNumber, $this->combine($headers, $values));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function openStream(string $source)
    {
        $handle = fopen($source, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open CSV file "%s".', $source));
        }

        if ($this->encoding->isUtf8()) {
            $this->encoding->consumeBomFromStream($handle);

            return $handle;
        }

        $filter = sprintf('convert.iconv.%s/UTF-8', $this->encoding->name);
        $added = stream_filter_append($handle, $filter, STREAM_FILTER_READ);

        if ($added === false) {
            fclose($handle);

            throw new RuntimeException(sprintf('Unable to convert encoding "%s" to UTF-8.', $this->encoding->name));
        }

        return $handle;
    }

    /**
     * @param list<string|null> $headers
     *
     * @return list<string|null>
     */
    private function normalizeHeaders(array $headers): array
    {
        if ($headers === [] || $headers[0] === null) {
            return $headers;
        }

        $headers[0] = $this->encoding->stripBomFrom($headers[0]);

        return $headers;
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
