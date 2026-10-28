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
 * lifecycle event, and (from step 8) adds them to the logging context.
 * Correlating an execution with the rest of an application's telemetry
 * is the whole purpose.
 *
 * This is an options object rather than extra parameters on start(),
 * run(), and dispatch() so that later milestones can record more
 * without changing four signatures again.
 */
readonly class ExecutionOptions
{
    /**
     * Matches the `string` columns these are persisted to. A value that
     * would be silently truncated by the database is rejected instead:
     * a half-written correlation id correlates with nothing.
     */
    public const MAX_LENGTH = 255;

    public ?string $correlationId;

    public ?string $tenantId;

    /**
     * @param  string|null  $correlationId  Ties this execution to the request,
     *                                      job, or external operation that caused it.
     * @param  string|null  $tenantId  Ties it to a tenant, for listing and retention
     *                                 in multi-tenant applications.
     * @param  int|string|null  $parentExecutionId  The execution whose step spawned
     *                                              this one; written by the sub-workflow machinery.
     * @param  int|string|null  $parentStepExecutionId  The exact parent step row waiting
     *                                                  on this execution.
     *
     * @throws InvalidExecutionOptionsException
     */
    public function __construct(
        ?string $correlationId = null,
        ?string $tenantId = null,
        public int|string|null $parentExecutionId = null,
        public int|string|null $parentStepExecutionId = null,
    ) {
        $this->correlationId = self::normalize($correlationId, 'correlationId');
        $this->tenantId = self::normalize($tenantId, 'tenantId');
    }

    /**
     * True when nothing was supplied — the overwhelmingly common case,
     * which callers use to skip work rather than branch on two nulls.
     */
    public function isEmpty(): bool
    {
        return $this->correlationId === null
            && $this->tenantId === null
            && $this->parentExecutionId === null;
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
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw InvalidExecutionOptionsException::controlCharacters($name);
        }

        return $value;
    }
}
