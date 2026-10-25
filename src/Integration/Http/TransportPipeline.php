<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Illuminate\Cache\RateLimiter;
use Laraflow\Integration\Connection;
use Laraflow\Support\Sleeper;
use Psr\Clock\ClockInterface;

/**
 * Wraps a connector's base transport in the behaviour its options ask
 * for. Nothing is wrapped that is not enabled, so with the shipped
 * defaults a request goes straight to the wire.
 *
 * @internal Resolved by HttpConnector from the container.
 */
class TransportPipeline
{
    public function __construct(
        protected Sleeper $sleeper,
        protected ClockInterface $clock,
        protected RateLimiter $limiter,
    ) {}

    /**
     * Throttle innermost so a retried attempt is counted like any
     * other; retries outermost.
     */
    public function through(HttpTransport $base, HttpOptions $options, Connection $connection): HttpTransport
    {
        $transport = $base;

        if ($options->rateLimit !== null) {
            $transport = new ThrottlingTransport($transport, $options->rateLimit, $connection, $this->limiter, $this->sleeper);
        }

        if ($options->retries->enabled()) {
            $transport = new RetryingTransport($transport, $options->retries, $this->sleeper, $this->clock);
        }

        return $transport;
    }
}
