<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Driver\Persistence\Postgres;

use Ivanfuhr\Ingestor\Conflict\DuplicateInBatch;
use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use Ivanfuhr\Ingestor\Context\ArrayRowContext;
use Ivanfuhr\Ingestor\Driver\Persistence\Postgres\ConflictRowDeduplicator;
use Ivanfuhr\Ingestor\Driver\Persistence\Postgres\StagingInsertBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConflictRowDeduplicatorTest extends TestCase
{
    private ConflictRowDeduplicator $deduplicator;

    protected function setUp(): void
    {
        $this->deduplicator = new ConflictRowDeduplicator();
    }

    #[Test]
    public function it_keeps_last_duplicate_by_default(): void
    {
        $buffer = $this->buffer(
            UpdateOnConflict::by('document'),
            [
                $this->row(2, ['document' => '111', 'name' => 'First'], ['111', 'First']),
                $this->row(3, ['document' => '111', 'name' => 'Second'], ['111', 'Second']),
                $this->row(4, ['document' => '222', 'name' => 'Bob'], ['222', 'Bob']),
            ],
        );

        $resolution = $this->deduplicator->resolve($buffer);

        $this->assertSame([], $resolution['failures']);
        $this->assertCount(2, $resolution['rows']);
        $this->assertSame(3, $resolution['rows'][0]['context']->line());
        $this->assertSame('Second', $resolution['rows'][0]['context']->data()['name']);
        $this->assertSame(4, $resolution['rows'][1]['context']->line());
    }

    #[Test]
    public function it_keeps_first_duplicate_when_configured(): void
    {
        $buffer = $this->buffer(
            UpdateOnConflict::by('document', DuplicateInBatch::FirstWins),
            [
                $this->row(2, ['document' => '111', 'name' => 'First'], ['111', 'First']),
                $this->row(3, ['document' => '111', 'name' => 'Second'], ['111', 'Second']),
            ],
        );

        $resolution = $this->deduplicator->resolve($buffer);

        $this->assertSame([], $resolution['failures']);
        $this->assertCount(1, $resolution['rows']);
        $this->assertSame(2, $resolution['rows'][0]['context']->line());
        $this->assertSame('First', $resolution['rows'][0]['context']->data()['name']);
    }

    #[Test]
    public function it_fails_on_duplicate_conflict_keys(): void
    {
        $buffer = $this->buffer(
            UpdateOnConflict::by('document', DuplicateInBatch::Fail),
            [
                $this->row(2, ['document' => '111', 'name' => 'First'], ['111', 'First']),
                $this->row(3, ['document' => '111', 'name' => 'Second'], ['111', 'Second']),
                $this->row(4, ['document' => '222', 'name' => 'Bob'], ['222', 'Bob']),
            ],
        );

        $resolution = $this->deduplicator->resolve($buffer);

        $this->assertSame([], $resolution['rows']);
        $this->assertCount(2, $resolution['failures']);
        $this->assertStringContainsString('lines 2, 3', $resolution['failures'][0]->message());
    }

    #[Test]
    public function it_deduplicates_composite_conflict_keys(): void
    {
        $buffer = $this->buffer(
            UpdateOnConflict::by('cpf', 'rg'),
            [
                $this->row(2, ['cpf' => '111', 'rg' => 'AA', 'name' => 'First'], ['111', 'AA', 'First']),
                $this->row(3, ['cpf' => '111', 'rg' => 'AA', 'name' => 'Second'], ['111', 'AA', 'Second']),
                $this->row(4, ['cpf' => '111', 'rg' => 'BB', 'name' => 'Other'], ['111', 'BB', 'Other']),
            ],
            ['cpf', 'rg', 'name'],
        );

        $resolution = $this->deduplicator->resolve($buffer);

        $this->assertCount(2, $resolution['rows']);
        $this->assertSame('Second', $resolution['rows'][0]['context']->data()['name']);
        $this->assertSame('Other', $resolution['rows'][1]['context']->data()['name']);
    }

    /**
     * @param list<array{context: ArrayRowContext, values: list<mixed>}> $rows
     * @param list<string> $columns
     */
    private function buffer(UpdateOnConflict $conflict, array $rows, array $columns = ['document', 'name']): StagingInsertBuffer
    {
        return new StagingInsertBuffer(
            dataset: 'customers',
            conflict: $conflict,
            columns: $columns,
            rows: $rows,
            count: count($rows),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<mixed> $values
     *
     * @return array{context: ArrayRowContext, values: list<mixed>}
     */
    private function row(int $line, array $data, array $values): array
    {
        return [
            'context' => new ArrayRowContext($line, $data),
            'values' => $values,
        ];
    }
}
