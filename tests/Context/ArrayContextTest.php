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

    #[Test]
    public function it_looks_up_values_in_a_store(): void
    {
        $context = new ArrayContext();

        $context->put('orders', ['ORD-1' => 42, 'ORD-2' => 99]);

        $this->assertSame(42, $context->get('orders', 'ORD-1'));
        $this->assertSame(99, $context->get('orders', 'ORD-2'));
        $this->assertNull($context->get('orders', 'ORD-999'));
    }

    #[Test]
    public function it_returns_default_when_lookup_key_is_missing(): void
    {
        $context = new ArrayContext();

        $context->put('orders', ['ORD-1' => 42]);

        $this->assertSame(0, $context->get('orders', 'ORD-999', 0));
        $this->assertSame('unknown', $context->get('orders', 'ORD-999', 'unknown'));
    }

    #[Test]
    public function it_returns_default_when_lookup_store_is_missing(): void
    {
        $context = new ArrayContext();

        $this->assertSame(0, $context->get('orders', 'ORD-1', 0));
        $this->assertNull($context->get('orders', 'ORD-1', null));
    }

    #[Test]
    public function it_returns_null_for_null_lookup_keys(): void
    {
        $context = new ArrayContext();

        $context->put('orders', ['ORD-1' => 42]);

        $this->assertNull($context->get('orders', null));
        $this->assertSame(0, $context->get('orders', null, 0));
    }

    #[Test]
    public function it_throws_when_lookup_target_is_not_an_array(): void
    {
        $context = new ArrayContext();

        $context->put('marker', 'ready');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context key "marker" is not a lookup map.');

        $context->get('marker', 'key', 'fallback');
    }

    #[Test]
    public function it_ignores_non_lookup_second_argument_when_store_exists(): void
    {
        $context = new ArrayContext();

        $context->put('customers', ['111' => 1]);

        $this->assertSame(['111' => 1], $context->get('customers', []));
    }

    #[Test]
    public function it_checks_lookup_keys_with_has(): void
    {
        $context = new ArrayContext();

        $context->put('cities', ['SP' => true, 'RJ' => true]);

        $this->assertTrue($context->has('cities', 'SP'));
        $this->assertFalse($context->has('cities', 'MG'));
        $this->assertFalse($context->has('cities', null));
        $this->assertFalse($context->has('missing', 'SP'));
    }
}
