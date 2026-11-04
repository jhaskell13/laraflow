<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A trigger request verified and its signal was durably recorded.
 * Fired after the record; the payload is never on the event — it is
 * the waiting step's output, as for any signal. `$trigger` is the
 * named trigger, or `signed` for the direct endpoint.
 */
final readonly class TriggerAccepted
{
    public function __construct(
        public string $trigger,
        public int $executionId,
        public string $signal,
    ) {}
}
