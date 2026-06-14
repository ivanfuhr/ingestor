<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor;

use DateTimeInterface;

final readonly class Duration
{
    public function __construct(
        private int $seconds,
        private int $microseconds = 0,
    ) {
    }

    public static function between(DateTimeInterface $start, DateTimeInterface $end): self
    {
        $startSeconds = (int) $start->format('U');
        $endSeconds = (int) $end->format('U');
        $startMicroseconds = (int) $start->format('u');
        $endMicroseconds = (int) $end->format('u');

        $seconds = $endSeconds - $startSeconds;
        $microseconds = $endMicroseconds - $startMicroseconds;

        if ($microseconds < 0) {
            --$seconds;
            $microseconds += 1_000_000;
        }

        return new self($seconds, $microseconds);
    }

    public function seconds(): int
    {
        return $this->seconds;
    }

    public function microseconds(): int
    {
        return $this->microseconds;
    }

    public function totalSeconds(): float
    {
        return $this->seconds + $this->microseconds / 1_000_000;
    }
}
