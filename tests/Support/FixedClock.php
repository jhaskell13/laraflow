<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A PSR-20 clock frozen at a chosen instant and advanced explicitly.
 *
 * Unlike Date::setTestNow() this is local state, so a test can hold
 * several independent clocks — one per simulated worker, say — and no
 * frozen time can leak into another test.
 */
class FixedClock implements ClockInterface
{
    protected DateTimeImmutable $now;

    public function __construct(string|DateTimeImmutable $now)
    {
        $this->now = is_string($now) ? new DateTimeImmutable($now) : $now;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    public function set(string|DateTimeImmutable $now): void
    {
        $this->now = is_string($now) ? new DateTimeImmutable($now) : $now;
    }
}
