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
