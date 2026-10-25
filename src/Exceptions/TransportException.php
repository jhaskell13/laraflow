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
    public static function connecting(string $host, Throwable $previous): self
    {
        return new self("Unable to connect to {$host}.", previous: $previous);
    }
}
