<?php

declare(strict_types=1);

namespace Laraflow\Events;

use Laraflow\Workflow\RecoveryReason;

/**
 * The recovery mechanism (laraflow:recover) selected an interrupted
 * execution for retry: queued it, or — in sync mode — is about to run it
 * in-process. The claim still decides which worker actually executes, so
 * this event does not guarantee the retry will proceed.
 *
 * Lifecycle events carry identifiers and metadata only — never workflow
 * input, step output, or context (see docs/execution-lifecycle.md).
 */
readonly class WorkflowExecutionRetryScheduled
{
    /** @deprecated since 0.3.0, removed in 0.4.0 — use RecoveryReason::StaleClaim (`$reason->value` for the string). */
    public const REASON_STALE_CLAIM = 'stale_claim';

    /** @deprecated since 0.3.0, removed in 0.4.0 — use RecoveryReason::NeverDispatched. */
    public const REASON_NEVER_DISPATCHED = 'never_dispatched';

    /** @deprecated since 0.3.0, removed in 0.4.0 — use RecoveryReason::RetryDue. */
    public const REASON_RETRY_DUE = 'retry_due';

    /** @deprecated since 0.3.0, removed in 0.4.0 — use RecoveryReason::StepTimeout. */
    public const REASON_STEP_TIMEOUT = 'step_timeout';

    /** @deprecated since 0.3.0, removed in 0.4.0 — use RecoveryReason::WaitDue. */
    public const REASON_WAIT_DUE = 'wait_due';

    /**
     * @param  string  $workflow  Workflow class name.
     * @param  RecoveryReason  $reason  Why recovery selected it; `->value` is the
     *                                  string this event carried before Milestone 8.
     * @param  int  $attempts  Highest step attempt count so far, 0 if nothing ran.
     * @param  string|null  $correlationId  Application-supplied identifier
     *                                      recorded on the execution, or null if none was supplied.
     * @param  string|null  $tenantId  Application-supplied tenant identifier,
     *                                 or null if none was supplied.
     */
    public function __construct(
        public int $executionId,
        public string $workflow,
        public RecoveryReason $reason,
        public int $attempts,
        public ?string $correlationId = null,
        public ?string $tenantId = null,
    ) {}
}
