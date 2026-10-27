<?php

declare(strict_types=1);

namespace Laraflow\Support;

use DateTimeImmutable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Wall-clock milliseconds between two instants.
 *
 * Shared by StepAttempt (how long one attempt ran) and
 * WorkflowExecution (how long the whole execution took) so the two
 * cannot drift. Microseconds are read from the formatted timestamp
 * because DateTimeImmutable::diff() discards them below the second on
 * some platforms, and a step that takes 12 ms would otherwise be
 * reported as taking none.
 */
class Duration
{
    public static function between(?DateTimeImmutable $from, ?DateTimeImmutable $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        $ms = ((float) $to->format('U.u') - (float) $from->format('U.u')) * 1000;

        return max(0, (int) round($ms));
    }
}
