<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Workflow\Scheduling\ScheduledWorkflow;
use Laraflow\Workflow\Scheduling\ScheduleRepository;
use Laraflow\Workflow\Scheduling\ScheduleStatus;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Finds what needs an operator: retries and waits past due, running
 * steps past their deadline, stalled schedules, executions a tenant
 * ceiling is holding, and the oldest unfinished execution.
 * Extracted from laraflow:summary so the dashboard's overview computes
 * the same answer by calling the same code, rather than a second copy
 * that could drift (docs/dashboard.md).
 *
 * Every scan is scoped by the filter the way every other number on the
 * summary is: an unscoped scan would report another tenant's retries
 * under a tenant filter and fail a health check because of them. And
 * every scoped workflow is scanned, not only the first — a check that
 * reported A's retries while B's were overdue would pass on a real
 * problem.
 */
final class AttentionScan
{
    public function __construct(
        private readonly ExecutionRepository $repository,
        private readonly ScheduleRepository $schedules,
    ) {}

    public function scan(ExecutionFilter $filter, DateTimeImmutable $now): AttentionReport
    {
        $dueRetries = $this->acrossWorkflows($filter, fn (?string $workflow): array => $this->repository->dueForRetry(
            $now,
            ExecutionRepository::MAX_PAGE_SIZE,
            $workflow,
            $filter->tenantId,
        ));

        $dueWaits = $this->acrossWorkflows($filter, fn (?string $workflow): array => $this->repository->waitingDue(
            $now,
            ExecutionRepository::MAX_PAGE_SIZE,
            $workflow,
            $filter->tenantId,
        ));

        $unfinished = $this->repository->list(
            $filter
                ->withStatus([ExecutionStatus::Pending, ExecutionStatus::Running, ExecutionStatus::Retrying, ExecutionStatus::Waiting, ExecutionStatus::Compensating])
                ->withOldestFirst(),
            limit: 1,
        );

        return new AttentionReport(
            dueRetries: $dueRetries,
            dueWaits: $dueWaits,
            overdueSteps: $this->overdueSteps($filter, $now),
            stalledSchedules: $this->stalledSchedules($filter, $now),
            deferred: $this->repository->deferred($filter, $now, ExecutionRepository::MAX_PAGE_SIZE),
            oldestUnfinished: $unfinished->isEmpty() ? null : $unfinished->executions[0],
        );
    }

    /**
     * Whether this step is a running step past its deadline — the
     * marker laraflow:show prints as (overdue). Laraflow does not
     * interrupt running PHP, so nothing acts on it while the claim is
     * fresh; an operator still deserves to see it.
     */
    public static function isOverdue(StepExecution $step, DateTimeImmutable $now): bool
    {
        return $step->isRunning()
            && $step->timeoutAt !== null
            && $step->timeoutAt <= $now;
    }

    /**
     * Running executions whose current step has passed its deadline.
     *
     * Bounded by one page of running executions: a healthy installation
     * has few, and an unhealthy one does not need an exhaustive list to
     * know it is unhealthy.
     *
     * @return list<array{execution: WorkflowExecution, step: StepExecution}>
     */
    private function overdueSteps(ExecutionFilter $filter, DateTimeImmutable $now): array
    {
        $running = $this->repository->list(
            // Compensating too: an undo past its deadline is a hung
            // step like any other, and the operator's one "something
            // is wrong" signal must see it.
            $filter->withStatus([ExecutionStatus::Running, ExecutionStatus::Compensating]),
            limit: ExecutionRepository::MAX_PAGE_SIZE,
        );

        $overdue = [];

        foreach ($running as $execution) {
            foreach ($this->repository->steps($execution) as $step) {
                if (self::isOverdue($step, $now)) {
                    $overdue[] = ['execution' => $execution, 'step' => $step];

                    break;
                }
            }
        }

        return $overdue;
    }

    /**
     * Schedules whose due ticks stand unclaimed — the scheduler stopped
     * running. Scoped like everything else here: a tenant's health
     * check must not fail for another tenant's schedule. Bounded by how
     * many schedules exist, which is the registration count, not
     * history.
     *
     * @return list<ScheduledWorkflow>
     */
    private function stalledSchedules(ExecutionFilter $filter, DateTimeImmutable $now): array
    {
        $stalled = [];

        foreach ($this->schedules->all() as $row) {
            if ($filter->workflows !== null && ! in_array($row->workflow, $filter->workflows, true)) {
                continue;
            }

            if ($filter->tenantId !== null && $row->tenantId !== $filter->tenantId) {
                continue;
            }

            if (ScheduleStatus::stalled($row, $now)) {
                $stalled[] = $row;
            }
        }

        return $stalled;
    }

    /**
     * Run a per-workflow scan once per scoped workflow (or once,
     * unscoped), merged most-overdue first.
     *
     * @param  callable(?string): array<WorkflowExecution>  $scan
     * @return list<WorkflowExecution>
     */
    private function acrossWorkflows(ExecutionFilter $filter, callable $scan): array
    {
        $results = [];

        foreach ($filter->workflows ?? [null] as $workflow) {
            foreach ($scan($workflow) as $execution) {
                $results[] = $execution;
            }
        }

        usort($results, fn (WorkflowExecution $a, WorkflowExecution $b): int => ($a->nextRetryAt?->getTimestamp() ?? 0) <=> ($b->nextRetryAt?->getTimestamp() ?? 0));

        return $results;
    }
}
