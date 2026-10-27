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
     * transport retry policy repeated the request. Recorded by the
     * retrying transport, read by the observer.
     */
    protected int $attempts = 1;

    public static function connecting(string $host, Throwable $previous): self
    {
        return new self("Unable to connect to {$host}.", previous: $previous);
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Record the attempts spent reaching this failure.
     *
     * Unlike the value objects around it this records in place rather
     * than returning a copy: PHP exceptions cannot be cloned, and
     * rebuilding one would move the throw site to this method and lose
     * the stack trace that makes it worth reporting. The property is
     * not public, so only the retrying transport that counted the
     * attempts can set them.
     */
    public function afterAttempts(int $attempts): static
    {
        $this->attempts = $attempts;

        return $this;
    }
}
