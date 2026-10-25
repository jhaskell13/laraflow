<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

use Throwable;

/**
 * No HTTP response was obtained: the connection failed, timed out, or
 * was refused. Never carries request options, which hold credentials.
 */
class TransportException extends ConnectorException
{
    /**
     * Wire attempts spent before giving up; more than one means a
     * transport retry policy repeated the request. Set by the retrying
     * transport, read by the observer.
     */
    public int $attempts = 1;

    public static function connecting(string $host, Throwable $previous): self
    {
        return new self("Unable to connect to {$host}.", previous: $previous);
    }

    public function afterAttempts(int $attempts): static
    {
        $this->attempts = $attempts;

        return $this;
    }
}
