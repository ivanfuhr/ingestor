<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Tests\Context;

use InvalidArgumentException;
use Ivanfuhr\Ingestor\Context\ArrayContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArrayContextTest extends TestCase
{
    #[Test]
    public function it_stores_and_retrieves_values(): void
    {
        $context = new ArrayContext();

        $context->put('customers', ['111' => 1]);
        $context->put('config', ['chunk_size' => 500]);

        $this->assertTrue($context->has('customers'));
        $this->assertSame(['111' => 1], $context->get('customers'));
        $this->assertSame(['chunk_size' => 500], $context->get('config'));
    }

    #[Test]
    public function it_overwrites_existing_keys(): void
    {
        $context = new ArrayContext();

        $context->put('key', 'first');
        $context->put('key', 'second');

        $this->assertSame('second', $context->get('key'));
    }

    #[Test]
    public function it_reports_missing_keys(): void
    {
        $context = new ArrayContext();

        $this->assertFalse($context->has('missing'));
    }

    #[Test]
    public function it_throws_when_getting_a_missing_key(): void
    {
        $context = new ArrayContext();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context key "missing" is not set.');

        $context->get('missing');
    }

    #[Test]
    public function it_distinguishes_null_from_missing_keys(): void
    {
        $context = new ArrayContext();

        $context->put('nullable', null);

        $this->assertTrue($context->has('nullable'));
        $this->assertNull($context->get('nullable'));
    }

    #[Test]
    public function it_returns_default_when_key_is_missing(): void
    {
        $context = new ArrayContext();

        $this->assertSame([], $context->get('missing', []));
        $this->assertSame('fallback', $context->get('missing', 'fallback'));
    }

    #[Test]
    public function it_returns_explicit_null_default_when_key_is_missing(): void
    {
        $context = new ArrayContext();

        $this->assertNull($context->get('missing', null));
    }
}
