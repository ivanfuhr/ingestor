<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Source;

use InvalidArgumentException;

final readonly class XlsxSheet
{
    public function __construct(
        public int $index = 0,
        public ?string $name = null,
    ) {
        if ($index < 0) {
            throw new InvalidArgumentException('Sheet index must be zero or positive.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Sheet name must not be empty.');
        }
    }

    public static function first(): self
    {
        return new self();
    }

    public static function byIndex(int $index): self
    {
        return new self(index: $index);
    }

    public static function byName(string $name): self
    {
        return new self(name: $name);
    }

    public function selectsByName(): bool
    {
        return $this->name !== null;
    }
}
