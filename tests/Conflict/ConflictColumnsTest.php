<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Conflict;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Conflict\ConflictColumns;
use Ivanfuhr\Ingestor\Conflict\UpdateOnConflict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConflictColumnsTest extends TestCase
{
    #[Test]
    public function it_accepts_a_single_column(): void
    {
        $this->assertSame(['document'], ConflictColumns::from('document'));
    }

    #[Test]
    public function it_accepts_multiple_columns(): void
    {
        $this->assertSame(['cpf', 'rg'], ConflictColumns::from('cpf', 'rg'));
    }

    #[Test]
    public function it_requires_at_least_one_column(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one conflict column must be provided.');

        ConflictColumns::from();
    }

    #[Test]
    public function it_exposes_columns_through_conflict_strategies(): void
    {
        $this->assertSame(
            ['cpf', 'rg'],
            UpdateOnConflict::by('cpf', 'rg')->columns(),
        );
    }
}
