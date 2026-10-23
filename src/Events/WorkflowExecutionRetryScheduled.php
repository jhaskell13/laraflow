<?php

declare(strict_types=1);

namespace Laraflow\Events;

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
    public const REASON_STALE_CLAIM = 'stale_claim';

    public const REASON_NEVER_DISPATCHED = 'never_dispatched';

    /**
     * @param  string  $workflow  Workflow class name.
     * @param  string  $reason  One of the REASON_* constants.
     * @param  int  $attempts  Highest step attempt count so far, 0 if nothing ran.
     */
    public function __construct(
        public int|string $executionId,
        public string $workflow,
        public string $reason,
        public int $attempts,
    ) {}
}
