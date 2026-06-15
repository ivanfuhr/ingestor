<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Driver\Source;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Contract\RowContext;
use Ivanfuhr\Ingestor\Driver\Source\XlsxDriver;
use Ivanfuhr\Ingestor\Driver\Source\XlsxSheet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class XlsxDriverTest extends TestCase
{
    #[Test]
    public function it_reads_rows_with_headers(): void
    {
        $path = $this->createXlsx([
            $this->sheetXml([
                ['cpf', 'name', 'city'],
                ['111', 'Ada', 'SP'],
                ['222', 'Bob', 'RJ'],
            ]),
        ]);

        $xlsxDriver = new XlsxDriver();
        $rows = iterator_to_array($xlsxDriver->read($path));

        $this->assertCount(2, $rows);
        $this->assertInstanceOf(RowContext::class, $rows[0]);
        $this->assertSame(2, $rows[0]->line());
        $this->assertSame([
            'cpf' => '111',
            'name' => 'Ada',
            'city' => 'SP',
        ], $rows[0]->data());
        $this->assertSame(3, $rows[1]->line());
        $this->assertSame([
            'cpf' => '222',
            'name' => 'Bob',
            'city' => 'RJ',
        ], $rows[1]->data());
    }

    #[Test]
    public function it_returns_no_rows_for_header_only_sheet(): void
    {
        $path = $this->createXlsx([
            $this->sheetXml([
                ['cpf', 'name', 'city'],
            ]),
        ]);

        $xlsxDriver = new XlsxDriver();

        $this->assertSame([], iterator_to_array($xlsxDriver->read($path)));
    }

    #[Test]
    public function it_rejects_non_string_sources(): void
    {
        $xlsxDriver = new XlsxDriver();

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($xlsxDriver->read(['not', 'a', 'path']));
    }

    #[Test]
    public function it_reads_shared_strings(): void
    {
        $path = $this->createXlsx(
            sheets: [
                $this->sheetXml([
                    ['cpf', 'name'],
                    ['111', 'Ada'],
                    ['222', 'Bob'],
                ], sharedStringIndexes: [
                    [0 => '0', 1 => '1'],
                    [1 => '2'],
                    [1 => '3'],
                ]),
            ],
            sharedStrings: ['cpf', 'name', 'Ada', 'Bob'],
        );

        $xlsxDriver = new XlsxDriver();
        $rows = iterator_to_array($xlsxDriver->read($path));

        $this->assertCount(2, $rows);
        $this->assertSame([
            'cpf' => '111',
            'name' => 'Ada',
        ], $rows[0]->data());
        $this->assertSame([
            'cpf' => '222',
            'name' => 'Bob',
        ], $rows[1]->data());
    }

    #[Test]
    public function it_reads_inline_strings(): void
    {
        $path = $this->createXlsx([
            $this->sheetXml([
                ['cpf', 'name'],
                ['111', 'Ada'],
            ], inlineStringColumns: [0, 1]),
        ]);

        $xlsxDriver = new XlsxDriver();
        $rows = iterator_to_array($xlsxDriver->read($path));

        $this->assertCount(1, $rows);
        $this->assertSame([
            'cpf' => '111',
            'name' => 'Ada',
        ], $rows[0]->data());
    }

    #[Test]
    public function it_reads_a_sheet_by_name(): void
    {
        $path = $this->createXlsx(
            sheets: [
                $this->sheetXml([
                    ['cpf', 'name'],
                    ['111', 'Ada'],
                ]),
                $this->sheetXml([
                    ['sku', 'qty'],
                    ['A1', '3'],
                ]),
            ],
            sheetNames: ['Customers', 'Orders'],
        );

        $xlsxDriver = new XlsxDriver(XlsxSheet::byName('Orders'));
        $rows = iterator_to_array($xlsxDriver->read($path));

        $this->assertCount(1, $rows);
        $this->assertSame([
            'sku' => 'A1',
            'qty' => '3',
        ], $rows[0]->data());
    }

    #[Test]
    public function it_reads_a_sheet_by_index(): void
    {
        $path = $this->createXlsx(
            sheets: [
                $this->sheetXml([
                    ['cpf', 'name'],
                    ['111', 'Ada'],
                ]),
                $this->sheetXml([
                    ['sku', 'qty'],
                    ['A1', '3'],
                ]),
            ],
            sheetNames: ['Customers', 'Orders'],
        );

        $xlsxDriver = new XlsxDriver(XlsxSheet::byIndex(1));
        $rows = iterator_to_array($xlsxDriver->read($path));

        $this->assertCount(1, $rows);
        $this->assertSame([
            'sku' => 'A1',
            'qty' => '3',
        ], $rows[0]->data());
    }

    #[Test]
    public function it_fills_sparse_rows_with_null_values(): void
    {
        $path = $this->createXlsx([
            $this->sheetXml([
                ['cpf', 'name', 'city'],
                ['111', null, 'SP'],
            ]),
        ]);

        $xlsxDriver = new XlsxDriver();
        $rows = iterator_to_array($xlsxDriver->read($path));

        $this->assertCount(1, $rows);
        $this->assertSame([
            'cpf' => '111',
            'name' => null,
            'city' => 'SP',
        ], $rows[0]->data());
    }

    #[Test]
    public function it_rejects_invalid_sheet_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        XlsxSheet::byName('');
    }

    /**
     * @param list<string> $sheets
     * @param list<string> $sheetNames
     * @param list<string> $sharedStrings
     */
    private function createXlsx(array $sheets, array $sheetNames = ['Sheet1'], array $sharedStrings = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingestor-xlsx-');
        $this->assertNotFalse($path);

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('Unable to create XLSX test archive.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets), $sharedStrings !== []));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml(count($sheets)));

        if ($sharedStrings !== []) {
            $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml($sharedStrings));
        }

        foreach ($sheets as $index => $sheetXml) {
            $zip->addFromString(sprintf('xl/worksheets/sheet%d.xml', $index + 1), $sheetXml);
        }

        $zip->close();

        return $path;
    }

    /**
     * @param list<list<string|null>> $rows
     * @param array<int, array<int, string>> $sharedStringIndexes
     * @param list<int> $inlineStringColumns
     */
    private function sheetXml(
        array $rows,
        array $sharedStringIndexes = [],
        array $inlineStringColumns = [],
    ): string {
        $sheetRows = [];

        foreach ($rows as $rowIndex => $cells) {
            $rowNumber = $rowIndex + 1;
            $xmlCells = [];

            foreach ($cells as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }

                $reference = $this->cellReference($columnIndex, $rowNumber);

                if (isset($sharedStringIndexes[$rowIndex][$columnIndex])) {
                    $xmlCells[] = sprintf(
                        '<c r="%s" t="s"><v>%s</v></c>',
                        $reference,
                        $sharedStringIndexes[$rowIndex][$columnIndex],
                    );

                    continue;
                }

                if (in_array($columnIndex, $inlineStringColumns, true)) {
                    $xmlCells[] = sprintf(
                        '<c r="%s" t="inlineStr"><is><t>%s</t></is></c>',
                        $reference,
                        htmlspecialchars($value, ENT_XML1),
                    );

                    continue;
                }

                $xmlCells[] = sprintf(
                    '<c r="%s"><v>%s</v></c>',
                    $reference,
                    htmlspecialchars($value, ENT_XML1),
                );
            }

            $sheetRows[] = sprintf('<row r="%d">%s</row>', $rowNumber, implode('', $xmlCells));
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>%s</sheetData>'
            . '</worksheet>',
            implode('', $sheetRows),
        );
    }

    private function cellReference(int $columnIndex, int $rowNumber): string
    {
        return $this->columnLetters($columnIndex) . (string) $rowNumber;
    }

    private function columnLetters(int $columnIndex): string
    {
        $letters = '';
        $index = $columnIndex + 1;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(ord('A') + $remainder) . $letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    /**
     * @param list<string> $sheetNames
     */
    private function workbookXml(array $sheetNames): string
    {
        $sheets = [];

        foreach ($sheetNames as $index => $name) {
            $sheets[] = sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                htmlspecialchars($name, ENT_XML1),
                $index + 1,
                $index + 1,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . implode('', $sheets) . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(int $sheetCount): string
    {
        $relationships = [];

        for ($index = 1; $index <= $sheetCount; ++$index) {
            $relationships[] = sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index,
                $index,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', $relationships)
            . '</Relationships>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function contentTypesXml(int $sheetCount, bool $hasSharedStrings = false): string
    {
        $overrides = [
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
        ];

        if ($hasSharedStrings) {
            $overrides[] = '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        }

        for ($index = 1; $index <= $sheetCount; ++$index) {
            $overrides[] = sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . implode('', $overrides)
            . '</Types>';
    }

    /**
     * @param list<string> $sharedStrings
     */
    private function sharedStringsXml(array $sharedStrings): string
    {
        $items = [];

        foreach ($sharedStrings as $value) {
            $items[] = sprintf('<si><t>%s</t></si>', htmlspecialchars($value, ENT_XML1));
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($sharedStrings)
            . '" uniqueCount="'
            . count($sharedStrings)
            . '">'
            . implode('', $items)
            . '</sst>';
    }

}
