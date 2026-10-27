<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

use DateTimeInterface;

/**
 * An exception that knows when another attempt could succeed.
 *
 * When a step fails with one of these and its policy allows a retry,
 * the runner schedules the retry no sooner than the suggestion — up to
 * `laraflow.retry.max_suggested_delay` — instead of its own backoff
 * alone. A provider that said "wait 600 seconds" is then waited for
 * durably, with the worker released, rather than guessed at or ignored.
 */
interface SuggestsRetryDelay
{
    /**
     * Seconds from $now until another attempt is worth making, or null
     * when the source gave no usable figure.
     */
    public function suggestedRetryDelaySeconds(DateTimeInterface $now): ?int;
}
