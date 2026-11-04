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
 * The tenant is the running execution's, read from the durable record
 * the tenant-scoped rate limit reads (docs/tenancy.md), and null for a
 * call made outside any execution or inside one created without a
 * tenant — so a metrics bridge can attribute a call without guessing
 * (docs/metrics.md). The correlation id is not here: it is unbounded,
 * never a metric label, and Laravel's log context already carries it
 * inside a run — read `Context::get('laraflow.correlation_id')`.
 */
final readonly class ConnectorRequestSent
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
     * @param  string|null  $tenantId  The running execution's tenant, if any.
     *                                 Added in 0.7.0.
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
        public ?string $tenantId = null,
    ) {}
}
