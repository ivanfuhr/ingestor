<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Row;

use Ivanfuhr\Ingestor\Context\ArrayRowContext;
use Ivanfuhr\Ingestor\Row\Row;
use Ivanfuhr\Ingestor\Validation\Failure;
use Ivanfuhr\Ingestor\Validation\FailureWithLine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowTest extends TestCase
{
    #[Test]
    public function it_exposes_line_and_data(): void
    {
        $row = Row::make(5, ['document' => '111', 'name' => 'Ada']);

        $this->assertSame(5, $row->line());
        $this->assertSame('111', $row->get('document'));
        $this->assertSame(['document' => '111', 'name' => 'Ada'], $row->toArray());
    }

    #[Test]
    public function it_builds_from_row_context(): void
    {
        $context = new ArrayRowContext(3, ['city' => 'SP']);

        $row = Row::fromContext($context);

        $this->assertSame(3, $row->line());
        $this->assertSame('SP', $row->string('city'));
    }

    #[Test]
    public function it_detects_missing_and_filled_values(): void
    {
        $row = Row::make(1, ['document' => '', 'name' => 'Ada', 'phone' => null]);

        $this->assertTrue($row->has('name'));
        $this->assertTrue($row->missing('document'));
        $this->assertTrue($row->missing('phone'));
        $this->assertTrue($row->missing('email'));
        $this->assertTrue($row->filled('name'));
    }

    #[Test]
    public function it_coerces_scalar_values(): void
    {
        $row = Row::make(1, [
            'amount' => '42',
            'rate' => '3.14',
            'label' => 100,
        ]);

        $this->assertSame(42, $row->int('amount'));
        $this->assertEqualsWithDelta(3.14, $row->float('rate'), 0.0001);
        $this->assertSame('100', $row->string('label'));
        $this->assertNull($row->int('missing'));
        $this->assertSame(0, $row->int('missing', 0));
        $this->assertNull($row->string('empty', null));
    }

    #[Test]
    public function it_returns_default_for_empty_strings(): void
    {
        $row = Row::make(1, ['name' => '', 'city' => 'SP']);

        $this->assertNull($row->string('name'));
        $this->assertSame('', $row->string('name', ''));
        $this->assertSame('SP', $row->string('city'));
    }

    #[Test]
    public function it_returns_only_selected_keys(): void
    {
        $row = Row::make(1, ['cpf' => '111', 'name' => 'Ada', 'city' => 'SP']);

        $this->assertSame(
            ['cpf' => '111', 'name' => 'Ada'],
            $row->only(['cpf', 'name', 'missing']),
        );
    }

    #[Test]
    public function it_detects_empty_rows(): void
    {
        $this->assertTrue(Row::dataIsEmpty([]));
        $this->assertTrue(Row::dataIsEmpty(['cpf' => '', 'name' => null]));
        $this->assertTrue(Row::make(1, [])->isEmpty());
        $this->assertTrue(Row::make(1, ['cpf' => '   ', 'name' => "\t"])->isEmpty());
        $this->assertFalse(Row::make(1, ['cpf' => '111', 'name' => ''])->isEmpty());
        $this->assertFalse(Row::make(1, ['active' => '0'])->isEmpty());
    }
}

final class FailureWithLineTest extends TestCase
{
    #[Test]
    public function it_adds_line_when_missing(): void
    {
        $failure = Failure::error('document')->message('Required.');

        $enriched = FailureWithLine::from($failure, 7);

        $this->assertSame(7, $enriched->line());
        $this->assertSame('document', $enriched->field());
        $this->assertSame('Required.', $enriched->message());
    }

    #[Test]
    public function it_preserves_existing_line(): void
    {
        $failure = Failure::error('document')
            ->onLine(3)
            ->message('Required.');

        $enriched = FailureWithLine::from($failure, 7);

        $this->assertSame(3, $enriched->line());
    }
}
