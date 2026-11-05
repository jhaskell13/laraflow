<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Events\ConnectorRequestSent;
use Laraflow\Events\EventEmitter;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Observability\Tracing\Tracer;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\ExecutionScope;
use SensitiveParameter;
use Throwable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Turns one connector call into an event, applying the same error
 * policy the workflow runner applies: redact first, then truncate, and
 * omit the message entirely when the application asked for that.
 *
 * Owning it here keeps ObservingTransport to its one job and keeps the
 * redaction chokepoint from being duplicated at a second call site.
 *
 * The tenant comes from the execution scope at the moment the call
 * finishes — the same source ThrottlingTransport's tenant bucket reads —
 * never from anything ambient to the worker.
 */
final class ConnectorObserver
{
    protected ErrorRedactor $redactor;

    public function __construct(
        protected EventEmitter $events,
        ErrorRedactor $redactor,
        protected ExecutionScope $scope,
        protected Tracer $tracer,
        protected bool $includeErrorMessages = true,
        protected int $maxErrorLength = 65535,
    ) {
        $this->redactor = $redactor;
    }

    /**
     * The call is about to be sent: the tracer's span begins here, so
     * it covers transport retries and throttle waits as the event's
     * duration does.
     */
    public function starting(Connection $connection, #[SensitiveParameter] ConnectorRequest $request): void
    {
        $this->tracer->connectorCallStarted($connection, $request);
    }

    public function sent(Connection $connection, #[SensitiveParameter] ConnectorRequest $request, ConnectorResponse $response, int $durationMs): void
    {
        $this->events->emit(new ConnectorRequestSent(
            connector: $connection->connector,
            connection: $connection->name,
            method: strtoupper($request->method),
            path: $request->path(),
            status: $response->status(),
            successful: $response->successful(),
            durationMs: $durationMs,
            attempts: $response->attempts(),
            tenantId: $this->scope->tenantId(),
        ));

        // After the event, which carries the outcome the span records.
        $this->tracer->connectorCallEnded();
    }

    public function failed(Connection $connection, #[SensitiveParameter] ConnectorRequest $request, Throwable $exception, int $durationMs, int $attempts = 1): void
    {
        $this->events->emit(new ConnectorRequestFailed(
            connector: $connection->connector,
            connection: $connection->name,
            method: strtoupper($request->method),
            path: $request->path(),
            errorClass: $exception::class,
            error: $this->message($exception),
            durationMs: $durationMs,
            attempts: $attempts,
            tenantId: $this->scope->tenantId(),
        ));

        $this->tracer->connectorCallEnded();
    }

    protected function message(Throwable $exception): ?string
    {
        if (! $this->includeErrorMessages) {
            return null;
        }

        return mb_substr($this->redactor->redact($exception, $exception->getMessage()), 0, $this->maxErrorLength);
    }
}
