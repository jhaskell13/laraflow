<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A connector request produced no HTTP response: the connection failed
 * or timed out, or Laraflow's own rate limit refused to send it.
 *
 * An error *status* is not this event — that is ConnectorRequestSent
 * with `successful: false`. This is the case where nothing was
 * answered, which is the one an alert usually wants.
 *
 * Carries the exception class and, unless
 * `laraflow.events.include_error_messages` is false, its message —
 * already passed through the configured `ErrorRedactor`. Never request
 * options or headers.
 */
readonly class ConnectorRequestFailed
{
    public function __construct(
        public string $connector,
        public string $connection,
        public string $method,
        public string $path,
        public string $errorClass,
        public ?string $error,
        public int $durationMs,
        public int $attempts = 1,
    ) {}
}
