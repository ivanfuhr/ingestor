<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Driver\Source;

use InvalidArgumentException;

final readonly class SourceEncoding
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function __construct(
        public string $name = 'UTF-8',
        public BomHandling $bom = BomHandling::Keep,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Encoding name must not be empty.');
        }

        if (!$this->isUtf8() && !in_array($name, mb_list_encodings(), true)) {
            throw new InvalidArgumentException(sprintf('Unsupported encoding "%s".', $name));
        }
    }

    public static function utf8(BomHandling $bom = BomHandling::Strip): self
    {
        return new self('UTF-8', $bom);
    }

    public static function iso88591(): self
    {
        return new self('ISO-8859-1');
    }

    public static function windows1252(): self
    {
        return new self('Windows-1252');
    }

    public function isUtf8(): bool
    {
        return strcasecmp($this->name, 'UTF-8') === 0;
    }

    public function stripBomFrom(string $value): string
    {
        if ($this->bom !== BomHandling::Strip) {
            return $value;
        }

        if (!str_starts_with($value, self::UTF8_BOM)) {
            return $value;
        }

        return substr($value, 3);
    }

    /**
     * @param resource $handle
     */
    public function consumeBomFromStream($handle): void
    {
        if ($this->bom !== BomHandling::Strip) {
            return;
        }

        $bom = fread($handle, 3);

        if ($bom === false || $bom !== self::UTF8_BOM) {
            if ($bom !== false && $bom !== '') {
                fseek($handle, 0);
            }
        }
    }
}
