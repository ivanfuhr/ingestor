<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Driver\Source;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Contract\RowContext;
use Ivanfuhr\Ingestor\Driver\Source\CsvDriver;
use Ivanfuhr\Ingestor\Driver\Source\SourceEncoding;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CsvDriverTest extends TestCase
{
    #[Test]
    public function it_reads_rows_with_headers(): void
    {
        $path = $this->createCsv(<<<'CSV'
cpf,name,city
111,Ada,SP
222,Bob,RJ
CSV);

        $csvDriver = new CsvDriver();
        $rows = iterator_to_array($csvDriver->read($path));

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
    public function it_returns_no_rows_for_header_only_file(): void
    {
        $path = $this->createCsv("cpf,name,city\n");

        $csvDriver = new CsvDriver();

        $this->assertSame([], iterator_to_array($csvDriver->read($path)));
    }

    #[Test]
    public function it_rejects_non_string_sources(): void
    {
        $csvDriver = new CsvDriver();

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($csvDriver->read(['not', 'a', 'path']));
    }

    #[Test]
    public function it_strips_utf8_bom_from_file_start(): void
    {
        $path = $this->createCsv("\xEF\xBB\xBFcpf,name,city\n111,Ada,SP\n");

        $csvDriver = new CsvDriver(SourceEncoding::utf8());
        $rows = iterator_to_array($csvDriver->read($path));

        $this->assertCount(1, $rows);
        $this->assertSame([
            'cpf' => '111',
            'name' => 'Ada',
            'city' => 'SP',
        ], $rows[0]->data());
    }

    #[Test]
    public function it_reads_iso88591_encoded_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ingestor-csv-');
        $this->assertNotFalse($path);

        $contents = "cpf,name,city\n111,São,SP\n";
        file_put_contents($path, mb_convert_encoding($contents, 'ISO-8859-1', 'UTF-8'));

        $csvDriver = new CsvDriver(SourceEncoding::iso88591());
        $rows = iterator_to_array($csvDriver->read($path));

        $this->assertCount(1, $rows);
        $this->assertSame([
            'cpf' => '111',
            'name' => 'São',
            'city' => 'SP',
        ], $rows[0]->data());
    }

    #[Test]
    public function it_rejects_unsupported_encodings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SourceEncoding('NOT-A-REAL-ENCODING');
    }

    #[Test]
    public function it_keeps_blank_rows_by_default(): void
    {
        $path = $this->createCsv(<<<'CSV'
cpf,name
111,Ada
,
222,Bob
CSV);

        $rows = iterator_to_array((new CsvDriver())->read($path));

        $this->assertCount(3, $rows);
        $this->assertSame(['cpf' => '', 'name' => ''], $rows[1]->data());
    }

    #[Test]
    public function it_ignores_blank_rows_when_configured(): void
    {
        $path = $this->createCsv(<<<'CSV'
cpf,name
111,Ada
,
222,Bob
CSV);

        $rows = iterator_to_array(new CsvDriver(ignoreEmptyRows: true)->read($path));

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]->line());
        $this->assertSame(['cpf' => '111', 'name' => 'Ada'], $rows[0]->data());
        $this->assertSame(4, $rows[1]->line());
        $this->assertSame(['cpf' => '222', 'name' => 'Bob'], $rows[1]->data());
    }

    private function createCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingestor-csv-');
        $this->assertNotFalse($path);

        file_put_contents($path, $contents);

        return $path;
    }
}
