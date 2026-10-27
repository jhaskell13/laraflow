<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Illuminate\Cache\RateLimiter;
use Laraflow\Exceptions\Connector\ThrottledException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Support\Sleeper;
use SensitiveParameter;

/**
 * Holds a connection to its RateLimitPolicy before each wire attempt,
 * using Laravel's cache-backed rate limiter so every worker shares the
 * budget. Sits inside the retry decorator, so a retried attempt is
 * counted like any other.
 *
 * The ceiling is best effort. Asking the limiter and then counting
 * against it are two operations rather than one atomic one, and workers
 * waiting out the same window wake together, so enough concurrency can
 * run a window slightly over. Making it exact would need a lock held
 * across every request to a connection, which would cost more than the
 * provider's own 429 and the durable retry behind it already handle.
 *
 * @internal Assembled by TransportPipeline when a policy is configured.
 */
final class ThrottlingTransport implements HttpTransport
{
    public function __construct(
        private HttpTransport $inner,
        private RateLimitPolicy $policy,
        private Connection $connection,
        private RateLimiter $limiter,
        private Sleeper $sleeper,
    ) {}

    public function send(#[SensitiveParameter] ConnectorRequest $request, HttpOptions $options): ConnectorResponse
    {
        $key = "laraflow:throttle:{$this->connection->key()}";

        if ($this->limiter->tooManyAttempts($key, $this->policy->maxRequests)) {
            $wait = max(1, $this->limiter->availableIn($key));

            if ($wait > $this->policy->maxWaitSeconds) {
                throw ThrottledException::for($this->connection, $this->policy, $wait);
            }

            $this->sleeper->sleep($wait * 1000);
        }

        $this->limiter->hit($key, $this->policy->perSeconds);

        return $this->inner->send($request, $options);
    }
}
