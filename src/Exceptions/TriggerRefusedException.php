<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

use Laraflow\Triggers\TriggerRefusalReason;
use Throwable;

/**
 * A trigger request did not verify. Thrown by a `TriggerVerifier` to
 * refuse a provider's request; the signed endpoint's own gate answers
 * without throwing. Either way the answer is one `401` and the reason
 * goes to the log and the event, never to the caller
 * (docs/triggers.md, "The signature").
 */
class TriggerRefusedException extends LaraflowException
{
    public function __construct(
        string $message = 'The trigger request was refused.',
        public readonly TriggerRefusalReason $reason = TriggerRefusalReason::InvalidSignature,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unsigned(string $message = 'The request carries no usable signature.'): self
    {
        return new self($message, TriggerRefusalReason::Unsigned);
    }

    public static function stale(string $message = 'The request timestamp is outside the tolerance.'): self
    {
        return new self($message, TriggerRefusalReason::Stale);
    }

    public static function invalidSignature(string $message = 'The signature does not verify.'): self
    {
        return new self($message, TriggerRefusalReason::InvalidSignature);
    }
}
