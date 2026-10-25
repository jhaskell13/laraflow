<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Connector;

use DateTimeInterface;
use Laraflow\Exceptions\SuggestsRetryDelay;

/**
 * The provider asked us to slow down: 429, or a provider-specific
 * signal a connector's mapper recognises.
 *
 * Carries the provider's own wait, when it gave one, and offers it to
 * the runner as the retry delay, so a durable retry lands when the
 * provider said rather than when a backoff guessed.
 */
class RateLimitedException extends RequestFailedException implements SuggestsRetryDelay
{
    /**
     * The `Retry-After` header as sent: delay-seconds or an HTTP-date.
     */
    public function retryAfter(): ?string
    {
        $value = $this->response->header('Retry-After');

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * Seconds to wait, from the `Retry-After` header. An HTTP-date is
     * measured from $now, so the caller's clock governs; a date already
     * past means zero. Null when the provider gave no usable value.
     */
    public function retryAfterSeconds(DateTimeInterface $now): ?int
    {
        return $this->response->retryAfterSeconds($now);
    }

    /**
     * The provider's wait is the retry delay the runner should honour.
     */
    public function suggestedRetryDelaySeconds(DateTimeInterface $now): ?int
    {
        return $this->retryAfterSeconds($now);
    }
}
