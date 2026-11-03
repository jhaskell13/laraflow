<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Workflow\Scheduling\ScheduledWorkflow;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * What an AttentionScan found: the conditions worth an operator's
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
     * @param  list<ScheduledWorkflow>  $stalledSchedules  Schedules whose due ticks stand unclaimed — the scheduler stopped running.
     * @param  list<WorkflowExecution>  $deferred  Executions a tenant ceiling is holding right now, soonest due first.
     * @param  WorkflowExecution|null  $oldestUnfinished  The oldest execution not yet in a finished state.
     */
    public function __construct(
        public array $dueRetries,
        public array $dueWaits,
        public array $overdueSteps,
        public array $stalledSchedules,
        public array $deferred,
        public ?WorkflowExecution $oldestUnfinished,
    ) {}

    /**
     * Whether anything found here needs a person: the condition under
     * which laraflow:summary exits non-zero.
     */
    public function needsAttention(): bool
    {
        return $this->dueRetries !== [] || $this->dueWaits !== [] || $this->overdueSteps !== [] || $this->stalledSchedules !== [] || $this->deferred !== [];
    }
}
