<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A connector received an HTTP response.
 *
 * Fires for every outcome the provider answered, success or error —
 * `successful` and `status` distinguish them. A request that got no
 * response at all fires ConnectorRequestFailed instead.
 *
 * Carries identifiers and measurements only: **never** request options,
 * headers, or response bodies. The target is the path alone, because a
 * query string may carry a credential.
 *
 * Correlation and tenant identifiers are not on this event. A connector
 * can be called outside any workflow, and when it is called inside one
 * Laravel's log context already carries them: read
 * `Context::get('laraflow.correlation_id')` in a listener.
 */
readonly class ConnectorRequestSent
{
    /**
     * @param  string  $connector  Registered connector name, e.g. `github`.
     * @param  string  $connection  Connection name, e.g. `default`.
     * @param  string  $method  HTTP method, uppercased.
     * @param  string  $path  Request path, without query string.
     * @param  int  $status  HTTP status received.
     * @param  bool  $successful  Whether the connector counted it a success:
     *                            2xx and no mapped error. The connector's error
     *                            mapper runs before this event is emitted, so a
     *                            provider that reports failure in a 200 body
     *                            (Slack's `ok: false`) is false here.
     * @param  int  $durationMs  Wall-clock milliseconds for the whole call,
     *                           including any transport retries and throttle waits.
     * @param  int  $attempts  Wire attempts spent; more than one means a
     *                         transport retry policy repeated the request.
     */
    public function __construct(
        public string $connector,
        public string $connection,
        public string $method,
        public string $path,
        public int $status,
        public bool $successful,
        public int $durationMs,
        public int $attempts = 1,
    ) {}
}
