<?php

declare(strict_types=1);

namespace Workbench\App\Soak;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock that is wrong by a known amount.
 *
 * Bound in a scheduler process to make two schedulers disagree about
 * what minute it is — the clock-skew fault docs/soak.md names — and in
 * the retention process to put it two days into the future, which is
 * the only way a run lasting minutes can age anything into reach of
 * bounds that are counted in whole days.
 */
final class SkewedClock implements ClockInterface
{
    public function __construct(
        private readonly ClockInterface $inner,
        private readonly int $offsetSeconds,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->inner->now()->modify(sprintf('%+d seconds', $this->offsetSeconds));
    }
}
