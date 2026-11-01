<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * Why recovery selected an execution. Carried on RecoveryCandidate and
 * on WorkflowExecutionRetryScheduled; the backing values are the
 * strings the event carried before Milestone 8, so a listener matching
 * on them keeps matching through `->value`.
 *
 * Two of these are interruptions and bounded by the recovery
 * abandonment rule; three are scheduled work and exempt from it, their
 * bound being the step's own policy (docs/queue-and-recovery.md).
 */
enum RecoveryReason: string
{
    /** A `running` execution whose claim outlived `stale_after`: the worker died mid-run. */
    case StaleClaim = 'stale_claim';

    /**
     * A stale claim whose interrupted step had also passed its deadline:
     * the recovering worker charges the attempt to the step's retry
     * policy as a timeout rather than re-running it for free.
     */
    case StepTimeout = 'step_timeout';

    /** A policy-scheduled retry whose next_retry_at has arrived. */
    case RetryDue = 'retry_due';

    /** A waiting execution whose next event — a timed resume or a signal deadline — has arrived. */
    case WaitDue = 'wait_due';

    /** A `pending` execution older than `pending_after`: its queue dispatch was lost. Opt-in. */
    case NeverDispatched = 'never_dispatched';

    /**
     * Whether this is an interruption — crash-loop territory, held to
     * the abandonment bound — rather than scheduled work the runner
     * already bounds through the step's policy.
     */
    public function isInterruption(): bool
    {
        return match ($this) {
            self::StaleClaim, self::StepTimeout, self::NeverDispatched => true,
            self::RetryDue, self::WaitDue => false,
        };
    }

    /** The words the recover report and the documentation use. */
    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
