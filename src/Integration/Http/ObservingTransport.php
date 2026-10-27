<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use DateTimeImmutable;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Support\Duration;
use Laraflow\Support\LoggingContext;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Throwable;

/**
 * @internal Outermost layer of the transport pipeline.
 *
 * Times one connector call, publishes which connector and connection is
 * being talked to into Laravel's log context for its duration, and
 * announces the outcome.
 *
 * Outermost deliberately: the duration then covers transport retries
 * and throttle waits, so one logical call produces one event with the
 * time the caller actually waited, and `attempts` says how many wire
 * attempts went into it.
 */
final class ObservingTransport implements HttpTransport
{
    public function __construct(
        private HttpTransport $inner,
        private Connection $connection,
        private ConnectorObserver $observer,
        private ClockInterface $clock,
        private ?LoggingContext $logging = null,
    ) {}

    public function send(#[SensitiveParameter] ConnectorRequest $request, HttpOptions $options): ConnectorResponse
    {
        $startedAt = $this->clock->now();

        $this->logging?->enterConnector($this->connection);

        try {
            $response = $this->inner->send($request, $options);
        } catch (Throwable $exception) {
            $this->observer->failed(
                $this->connection,
                $request,
                $exception,
                $this->elapsed($startedAt),
                $exception instanceof TransportException ? $exception->attempts() : 1,
            );

            throw $exception;
        } finally {
            $this->logging?->leaveConnector();
        }

        $this->observer->sent($this->connection, $request, $response, $this->elapsed($startedAt));

        return $response;
    }

    private function elapsed(DateTimeImmutable $startedAt): int
    {
        return Duration::between($startedAt, $this->clock->now()) ?? 0;
    }
}
