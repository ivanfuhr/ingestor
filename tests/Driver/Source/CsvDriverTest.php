<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Driver\Source;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Driver\Source\CsvDriver;
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
        $this->assertSame([
            'cpf' => '111',
            'name' => 'Ada',
            'city' => 'SP',
        ], $rows[0]);
        $this->assertSame([
            'cpf' => '222',
            'name' => 'Bob',
            'city' => 'RJ',
        ], $rows[1]);
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

    private function createCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingestor-csv-');
        $this->assertNotFalse($path);

        file_put_contents($path, $contents);

        return $path;
    }
}
