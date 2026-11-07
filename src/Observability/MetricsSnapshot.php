<?php

declare(strict_types=1);

namespace Laraflow\Observability;

use DateTimeImmutable;
use Laraflow\Workflow\ExecutionStatus;

/**
 * What is true of the executions at one instant, counted exactly: what
 * a gauge in the application's own metrics should be set from
 * (docs/metrics.md).
 *
 * Events say what happened; this says what is. A gauge kept by counting
 * events up and down is wrong forever once one event is missed — a
 * worker killed between its write and its dispatch, a listener added
 * yesterday — so a gauge is read from the database each time instead.
 * Taken by Snapshots::take().
 *
 * Counts only: no ids, no payloads, nothing that grows with traffic
 * beyond the number of workflow classes an application declares.
 */
final readonly class MetricsSnapshot
{
    /**
     * The statuses `executions` counts: everything an operator may
     * still own. Completed and cancelled only ever grow, and the event
     * counters already carry them.
     */
    public const STATUSES = [
        ExecutionStatus::Pending,
        ExecutionStatus::Running,
        ExecutionStatus::Retrying,
        ExecutionStatus::Waiting,
        ExecutionStatus::Compensating,
        ExecutionStatus::Failed,
    ];

    /**
     * @param  array<string, array<string, int>>  $executions  Workflow class => status value => count, for STATUSES; a pair with none is absent.
     * @param  int|null  $oldestUnfinishedAgeSeconds  Whole seconds since the oldest unfinished execution was created; null when there is none.
     * @param  int  $retriesDue  Retrying executions whose backoff has elapsed.
     * @param  int  $waitsDue  Waiting executions whose resume time or deadline has passed.
     * @param  int  $overdueSteps  Running or compensating executions with a running step past its deadline, counted once per execution.
     * @param  int  $deferred  Executions a tenant ceiling is holding.
     * @param  int  $stalledSchedules  Schedules with due ticks standing unclaimed.
     */
    public function __construct(
        public array $executions,
        public ?int $oldestUnfinishedAgeSeconds,
        public int $retriesDue,
        public int $waitsDue,
        public int $overdueSteps,
        public int $deferred,
        public int $stalledSchedules,
        public DateTimeImmutable $takenAt,
    ) {}

    /**
     * Executions in $status, for one workflow class or across all of
     * them. Zero for a status the snapshot does not count.
     */
    public function count(ExecutionStatus $status, ?string $workflow = null): int
    {
        if ($workflow !== null) {
            return $this->executions[$workflow][$status->value] ?? 0;
        }

        return array_sum(array_map(
            fn (array $statuses): int => $statuses[$status->value] ?? 0,
            $this->executions,
        ));
    }

    /**
     * The workflow classes with at least one counted execution.
     *
     * @return list<string>
     */
    public function workflows(): array
    {
        return array_keys($this->executions);
    }
}
