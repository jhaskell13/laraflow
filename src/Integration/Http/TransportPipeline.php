<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Closure;
use Illuminate\Cache\RateLimiter;
use Laraflow\Integration\Connection;
use Laraflow\Support\LoggingContext;
use Laraflow\Support\Sleeper;
use Psr\Clock\ClockInterface;

/**
 * Wraps a connector's base transport in the behaviour its options ask
 * for. Nothing is wrapped that is not enabled, so with the shipped
 * defaults a request goes straight to the wire.
 *
 * Public only because an HttpConnector subclass declaring its own
 * constructor must type-hint and forward it; nothing beyond construction
 * is a contract.
 */
class TransportPipeline
{
    public function __construct(
        protected Sleeper $sleeper,
        protected ClockInterface $clock,
        protected RateLimiter $limiter,
        protected ConnectorObserver $observer = new ConnectorObserver,
        protected ?LoggingContext $logging = null,
    ) {}

    /**
     * Throttle innermost so a retried attempt is counted like any
     * other; retries around it; error mapping over the response that
     * survives them; observation outermost, so one logical call is one
     * event covering the time the caller actually waited — with a
     * `successful` flag the connector's mapper has already ruled on.
     *
     * @param  Closure(\Laraflow\Integration\ConnectorResponse): \Laraflow\Integration\ConnectorResponse|null  $map
     */
    public function through(HttpTransport $base, HttpOptions $options, Connection $connection, ?Closure $map = null): HttpTransport
    {
        $transport = $base;

        if ($options->rateLimit !== null) {
            $transport = new ThrottlingTransport($transport, $options->rateLimit, $connection, $this->limiter, $this->sleeper);
        }

        if ($options->retries->enabled()) {
            $transport = new RetryingTransport($transport, $options->retries, $this->sleeper, $this->clock);
        }

        if ($map !== null) {
            $transport = new MappingTransport($transport, $map);
        }

        return new ObservingTransport($transport, $connection, $this->observer, $this->clock, $this->logging);
    }
}
