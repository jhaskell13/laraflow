<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * What an AttentionScan found: the four conditions worth an operator's
 * attention, and the one execution that has been unfinished longest.
 * A value object the console prints and the dashboard renders, so the
 * two cannot disagree about what is wrong.
 */
final readonly class AttentionReport
{
    /**
     * @param  list<WorkflowExecution>  $dueRetries  Retrying executions past their next_retry_at, most overdue first.
     * @param  list<WorkflowExecution>  $dueWaits  Waiting executions whose timed resume or deadline has passed, most overdue first.
     * @param  list<array{execution: WorkflowExecution, step: StepExecution}>  $overdueSteps  Running executions whose current step is past its deadline.
     * @param  WorkflowExecution|null  $oldestUnfinished  The oldest execution not yet in a finished state.
     */
    public function __construct(
        public array $dueRetries,
        public array $dueWaits,
        public array $overdueSteps,
        public ?WorkflowExecution $oldestUnfinished,
    ) {}

    /**
     * Whether anything found here needs a person: the condition under
     * which laraflow:summary exits non-zero.
     */
    public function needsAttention(): bool
    {
        return $this->dueRetries !== [] || $this->dueWaits !== [] || $this->overdueSteps !== [];
    }
}
