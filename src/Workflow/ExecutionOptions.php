<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Exceptions\Workflow\InvalidExecutionOptionsException;

/**
 * Application-supplied identifiers recorded on an execution when it is
 * created: what request, job, tenant, or external operation caused it.
 *
 * Laraflow never interprets these values. It persists them, indexes
 * them so executions can be listed by them, exposes them to steps so a
 * step can forward them to an external provider, carries them on every
 * lifecycle event, and adds them to the logging context.
 * Correlating an execution with the rest of an application's telemetry
 * is the whole purpose.
 *
 * This is an options object rather than extra parameters on start(),
 * run(), and dispatch() so that later milestones can record more
 * without changing four signatures again.
 */
final readonly class ExecutionOptions
{
    /**
     * Matches the `string` columns these are persisted to. A value that
     * would be silently truncated by the database is rejected instead:
     * a half-written correlation id correlates with nothing.
     */
    public const MAX_LENGTH = 255;

    public ?string $correlationId;

    public ?string $tenantId;

    public ?string $traceContext;

    /**
     * @param  string|null  $correlationId  Ties this execution to the request,
     *                                      job, or external operation that caused it.
     * @param  string|null  $tenantId  Ties it to a tenant, for listing and retention
     *                                 in multi-tenant applications.
     * @param  int|null  $parentExecutionId  The execution whose step spawned
     *                                       this one. Written by the sub-workflow machinery;
     *                                       setting it yourself makes the execution behave as
     *                                       a child — cancelled by the parent's cascade and
     *                                       adoptable by its step — with no validation that
     *                                       the ids exist.
     * @param  int|null  $parentStepExecutionId  The exact parent step row waiting
     *                                           on this execution.
     * @param  string|null  $traceContext  The trace this execution belongs to, as
     *                                     a W3C `traceparent` (docs/tracing.md). Captured
     *                                     from the active trace when omitted; given, it
     *                                     joins an execution to a trace that arrived with
     *                                     a request — a webhook, another service's call.
     *                                     Added in 0.7.0.
     *
     * @throws InvalidExecutionOptionsException
     */
    public function __construct(
        ?string $correlationId = null,
        ?string $tenantId = null,
        public ?int $parentExecutionId = null,
        public ?int $parentStepExecutionId = null,
        ?string $traceContext = null,
    ) {
        $this->correlationId = self::normalize($correlationId, 'correlationId');
        $this->tenantId = self::normalize($tenantId, 'tenantId');

        $traceContext = $traceContext === null || trim($traceContext) === '' ? null : strtolower(trim($traceContext));

        if ($traceContext !== null && ! self::isTraceParent($traceContext)) {
            throw InvalidExecutionOptionsException::invalidTraceContext();
        }

        $this->traceContext = $traceContext;
    }

    /**
     * Whether $value is a version-00 W3C `traceparent` with a real trace
     * and span id. Only version 00 is accepted: it is the only version
     * defined, and the column holds exactly its 55 characters.
     */
    public static function isTraceParent(string $value): bool
    {
        return preg_match('/^00-(?!0{32})[0-9a-f]{32}-(?!0{16})[0-9a-f]{16}-[0-9a-f]{2}$/', $value) === 1;
    }

    /**
     * These options with the trace context set, unless one was given.
     */
    public function withTraceContextIfAbsent(?string $traceContext): self
    {
        if ($this->traceContext !== null || $traceContext === null) {
            return $this;
        }

        return new self(
            correlationId: $this->correlationId,
            tenantId: $this->tenantId,
            parentExecutionId: $this->parentExecutionId,
            parentStepExecutionId: $this->parentStepExecutionId,
            traceContext: $traceContext,
        );
    }

    /**
     * True when nothing was supplied — the overwhelmingly common case,
     * which callers use to skip work rather than branch on two nulls.
     */
    public function isEmpty(): bool
    {
        return $this->correlationId === null
            && $this->tenantId === null
            && $this->parentExecutionId === null
            && $this->parentStepExecutionId === null
            && $this->traceContext === null;
    }

    /**
     * Blank means absent, so `new ExecutionOptions(correlationId: $request->header('X-Request-Id'))`
     * is safe when the header is missing or empty — an empty string in an
     * indexed column is a value that matches nothing and means nothing.
     *
     * @throws InvalidExecutionOptionsException
     */
    protected static function normalize(?string $value, string $name): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidExecutionOptionsException::tooLong($name, mb_strlen($value), self::MAX_LENGTH);
        }

        // These values reach log records and logging context, where a
        // newline lets a crafted identifier forge a second log line.
        // Rejecting at the boundary keeps every downstream sink honest
        // without each one having to re-sanitise.
        // On malformed UTF-8, preg_match returns false rather than 0 —
        // which must read as a refusal, not a pass: a byte sequence the
        // guard cannot inspect is a byte sequence the sinks downstream
        // cannot be trusted with.
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw InvalidExecutionOptionsException::invalidEncoding($name);
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw InvalidExecutionOptionsException::controlCharacters($name);
        }

        return $value;
    }
}
