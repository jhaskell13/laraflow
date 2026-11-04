<?php

declare(strict_types=1);

namespace Laraflow\Events;

use Laraflow\Triggers\TriggerRefusalReason;

/**
 * A trigger request was refused, and why — the detail the response
 * deliberately withholds. Carries the execution id and signal when the
 * URL named them, and never a payload, a header, a key, or a client
 * address: an application that wants the address has it in its own
 * request log.
 */
final readonly class TriggerRefused
{
    public function __construct(
        public string $trigger,
        public TriggerRefusalReason $reason,
        public ?int $executionId,
        public ?string $signal,
    ) {}
}
