<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Source;

use InvalidArgumentException;
use RuntimeException;
use XMLReader;
use ZipArchive;
use Ivanfuhr\Ingestor\Context\ArrayRowContext;
use Ivanfuhr\Ingestor\Contract\SourceDriver;
use Ivanfuhr\Ingestor\Row\Row;

final class XlsxDriver implements SourceDriver
{
    private const OFFICE_RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private readonly XlsxSheet $sheet;

    public function __construct(
        ?XlsxSheet $sheet = null,
        private readonly bool $ignoreEmptyRows = false,
    ) {
        $this->sheet = $sheet ?? XlsxSheet::first();
    }

    /**
     * @return iterable<int, ArrayRowContext>
     */
    public function read(mixed $source): iterable
    {
        if (!is_string($source)) {
            throw new InvalidArgumentException('XLSX source must be a file path string.');
        }

        if (!is_readable($source)) {
            throw new RuntimeException(sprintf('XLSX file "%s" is not readable.', $source));
        }

        $this->assertExtensionsAvailable();

        $zipPath = $this->resolveZipPath($source);
        $this->assertZipContainsWorkbook($zipPath);

        $sheetPath = $this->resolveSheetPath($zipPath);
        $sharedStrings = $this->readSharedStrings($zipPath);

        yield from $this->readSheetRows($zipPath, $sheetPath, $sharedStrings);
    }

    private function assertExtensionsAvailable(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is required to read XLSX files.');
        }

        if (!class_exists(XMLReader::class)) {
            throw new RuntimeException('The xml extension is required to read XLSX files.');
        }
    }

    private function resolveZipPath(string $source): string
    {
        $realPath = realpath($source);

        if ($realPath === false) {
            throw new RuntimeException(sprintf('Unable to resolve XLSX file "%s".', $source));
        }

        return str_replace('\\', '/', $realPath);
    }

    private function assertZipContainsWorkbook(string $zipPath): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException(sprintf('Unable to open XLSX file "%s".', $zipPath));
        }

        if ($zip->locateName('xl/workbook.xml') === false) {
            $zip->close();

            throw new RuntimeException(sprintf('File "%s" is not a valid XLSX workbook.', $zipPath));
        }

        $zip->close();
    }

    /**
     * @return list<array{name: string, relationshipId: string}>
     */
    private function readWorkbookSheets(string $zipPath): array
    {
        $uri = $this->zipEntryUri($zipPath, 'xl/workbook.xml');
        $reader = $this->openXmlReader($uri);

        $sheets = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'sheet') {
                    continue;
                }

                $name = $reader->getAttribute('name');

                if ($name === null || $name === '') {
                    continue;
                }

                $relationshipId = $reader->getAttribute('r:id')
                    ?? $reader->getAttributeNS(self::OFFICE_RELATIONSHIPS_NAMESPACE, 'id');

                if ($relationshipId === null || $relationshipId === '') {
                    continue;
                }

                $sheets[] = [
                    'name' => $name,
                    'relationshipId' => $relationshipId,
                ];
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /**
     * @return array<string, string>
     */
    private function readWorkbookRelationships(string $zipPath): array
    {
        $uri = $this->zipEntryUri($zipPath, 'xl/_rels/workbook.xml.rels');
        $reader = $this->openXmlReader($uri);

        $relationships = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Relationship') {
                    continue;
                }

                $id = $reader->getAttribute('Id');
                $target = $reader->getAttribute('Target');

                if ($id === null || $target === null || $id === '' || $target === '') {
                    continue;
                }

                $relationships[$id] = $target;
            }
        } finally {
            $reader->close();
        }

        return $relationships;
    }

    private function resolveSheetPath(string $zipPath): string
    {
        $sheets = $this->readWorkbookSheets($zipPath);

        if ($sheets === []) {
            throw new RuntimeException('XLSX workbook does not contain any worksheets.');
        }

        $selected = $this->selectSheet($sheets);
        $relationships = $this->readWorkbookRelationships($zipPath);
        $target = $relationships[$selected['relationshipId']] ?? null;

        if ($target === null) {
            throw new RuntimeException(sprintf('Unable to resolve worksheet "%s".', $selected['name']));
        }

        return $this->normalizeSheetPath($target);
    }

    /**
     * @param list<array{name: string, relationshipId: string}> $sheets
     *
     * @return array{name: string, relationshipId: string}
     */
    private function selectSheet(array $sheets): array
    {
        if ($this->sheet->selectsByName()) {
            foreach ($sheets as $sheet) {
                if ($sheet['name'] === $this->sheet->name) {
                    return $sheet;
                }
            }

            throw new RuntimeException(sprintf('Worksheet "%s" was not found.', $this->sheet->name));
        }

        if (!array_key_exists($this->sheet->index, $sheets)) {
            throw new RuntimeException(sprintf('Worksheet index %d is out of range.', $this->sheet->index));
        }

        return $sheets[$this->sheet->index];
    }

    private function normalizeSheetPath(string $target): string
    {
        $path = str_starts_with($target, '/')
            ? ltrim($target, '/')
            : 'xl/' . ltrim($target, '/');

        return $path;
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(string $zipPath): array
    {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if ($opened !== true || $zip->locateName('xl/sharedStrings.xml') === false) {
            if ($opened === true) {
                $zip->close();
            }

            return [];
        }

        $zip->close();

        $uri = $this->zipEntryUri($zipPath, 'xl/sharedStrings.xml');
        $reader = new XMLReader();

        if (!$reader->open($uri)) {
            return [];
        }

        $sharedStrings = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                    continue;
                }

                $sharedStrings[] = $this->readTextContent($reader);
            }
        } finally {
            $reader->close();
        }

        return $sharedStrings;
    }

    /**
     * @param list<string> $sharedStrings
     *
     * @return iterable<int, ArrayRowContext>
     */
    private function readSheetRows(string $zipPath, string $sheetPath, array $sharedStrings): iterable
    {
        $uri = $this->zipEntryUri($zipPath, $sheetPath);
        $reader = $this->openXmlReader($uri);

        $headers = null;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $lineNumber = (int) ($reader->getAttribute('r') ?? 0);

                if ($lineNumber <= 0) {
                    continue;
                }

                $cells = $this->readRowCells($reader, $sharedStrings);

                if ($headers === null) {
                    $headers = $this->normalizeHeaders($cells);

                    if ($headers === []) {
                        return;
                    }

                    continue;
                }

                $row = $this->combine($headers, $cells);

                if ($this->ignoreEmptyRows && Row::dataIsEmpty($row)) {
                    continue;
                }

                yield new ArrayRowContext($lineNumber, $row);
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param list<string> $sharedStrings
     *
     * @return array<int, string|null>
     */
    private function readRowCells(XMLReader $reader, array $sharedStrings): array
    {
        $cells = [];
        $nextColumnIndex = 0;
        $depth = $reader->depth;

        if ($reader->isEmptyElement) {
            return $cells;
        }

        while ($reader->read() && $reader->depth > $depth) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }

            $reference = $reader->getAttribute('r');
            $columnIndex = $reference !== null
                ? self::columnIndexFromCellReference($reference)
                : $nextColumnIndex;

            $cells[$columnIndex] = $this->readCellValue($reader, $sharedStrings);
            $nextColumnIndex = $columnIndex + 1;
        }

        return $cells;
    }

    /**
     * @param list<string> $sharedStrings
     */
    private function readCellValue(XMLReader $reader, array $sharedStrings): ?string
    {
        $type = $reader->getAttribute('t');
        $depth = $reader->depth;
        $rawValue = null;
        $inlineText = '';

        if ($reader->isEmptyElement) {
            return $this->resolveCellValue($type, $rawValue, $inlineText, $sharedStrings);
        }

        while ($reader->read() && $reader->depth > $depth) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName === 'v') {
                $rawValue = $reader->readString();

                continue;
            }

            if ($reader->localName === 't') {
                $inlineText .= $reader->readString();

                continue;
            }

            if ($reader->localName === 'is') {
                $inlineText .= $this->readTextContent($reader);
            }
        }

        return $this->resolveCellValue($type, $rawValue, $inlineText, $sharedStrings);
    }

    /**
     * @param list<string> $sharedStrings
     */
    private function resolveCellValue(?string $type, ?string $rawValue, string $inlineText, array $sharedStrings): ?string
    {
        return match ($type) {
            's' => $sharedStrings[(int) $rawValue] ?? '',
            'inlineStr' => $inlineText !== '' ? $inlineText : null,
            'b' => $rawValue === '1' ? 'TRUE' : 'FALSE',
            'str' => $rawValue ?? ($inlineText !== '' ? $inlineText : null),
            default => $rawValue,
        };
    }

    private function readTextContent(XMLReader $reader): string
    {
        $depth = $reader->depth;
        $text = '';

        if ($reader->isEmptyElement) {
            return $text;
        }

        while ($reader->read() && $reader->depth > $depth) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                $text .= $reader->readString();
            }
        }

        return $text;
    }

    /**
     * @param array<int, string|null> $cells
     *
     * @return list<string|null>
     */
    private function normalizeHeaders(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $maxColumn = max(array_keys($cells));
        $headers = [];

        for ($column = 0; $column <= $maxColumn; ++$column) {
            $headers[] = $cells[$column] ?? null;
        }

        return $headers;
    }

    /**
     * @param list<string|null> $headers
     * @param array<int, string|null> $cells
     *
     * @return array<string, string|null>
     */
    private function combine(array $headers, array $cells): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === null || $header === '') {
                continue;
            }

            $row[$header] = $cells[$index] ?? null;
        }

        return $row;
    }

    private function openXmlReader(string $uri): XMLReader
    {
        $reader = new XMLReader();

        if (!$reader->open($uri)) {
            throw new RuntimeException(sprintf('Unable to read XML entry "%s".', $uri));
        }

        return $reader;
    }

    private function zipEntryUri(string $zipPath, string $entry): string
    {
        return 'zip://' . $zipPath . '#' . $entry;
    }

    private static function columnIndexFromCellReference(string $reference): int
    {
        $letters = (string) preg_replace('/\d+/', '', $reference);

        return self::columnIndexFromLetters($letters);
    }

    private static function columnIndexFromLetters(string $letters): int
    {
        $index = 0;

        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = ($index * 26) + (ord($letter) - ord('A') + 1);
        }

        return $index - 1;
    }
}
