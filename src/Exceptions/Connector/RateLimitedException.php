<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Connector;

use DateTimeInterface;

/**
 * The provider asked us to slow down: 429, or a provider-specific
 * signal a connector's mapper recognises.
 *
 * Carries the provider's own wait, when it gave one, so a retry can
 * honour it rather than guess.
 */
class RateLimitedException extends RequestFailedException
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
}
