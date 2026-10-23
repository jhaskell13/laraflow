<?php

declare(strict_types=1);

namespace Laraflow\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Psr\Clock\ClockInterface;

/**
 * @internal Default binding; applications may replace it in the container.
 *
 * The package's source of time: Laravel's Date facade, so an application's
 * clock configuration and `Date::setTestNow()` both apply to every
 * timestamp Laraflow writes.
 *
 * Laraflow reads time through an injected PSR-20 clock rather than calling
 * the facade directly so that scheduling decisions — which retry is due,
 * which claim is stale — can be driven by an explicit clock in a test,
 * without global state and without one test's frozen time leaking into
 * another's.
 */
class Clock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return Date::now()->toImmutable();
    }
}
