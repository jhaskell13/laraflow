<?php

declare(strict_types=1);

namespace Laraflow\Observability;

use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Workflow\AttentionScan;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;

/**
 * Takes a MetricsSnapshot: the one read a metrics bridge makes of the
 * database (docs/metrics.md).
 *
 * **Exact, and a fixed cost.** Every field is counted in the database,
 * never by listing rows: the attention scan behind laraflow:summary
 * stops at MAX_PAGE_SIZE because it shows rows, and a gauge that
 * quietly saturated at 500 would lie exactly when it matters. The
 * number of queries does not grow with the number of executions or
 * workflows — a grouped count, four attention counts, the oldest
 * unfinished row, and the registered schedules.
 *
 * **The summary's answer.** Each field shares its predicate with the
 * list laraflow:summary and the dashboard's overview print, so a
 * dashboard built on metrics and the console disagree about how much
 * they show, never about what needs attention.
 */
final class Snapshots
{
    public function __construct(
        private readonly ExecutionRepository $repository,
        private readonly AttentionScan $attention,
    ) {}

    /**
     * The snapshot at $now, for every execution or for the scope given.
     *
     * A scope narrows every field as laraflow:summary's options do — by
     * workflow, tenant, correlation, parent, and creation window. It may
     * not name statuses or a finished window: each field defines the
     * statuses it counts, and a scope that also did could only make a
     * field silently zero.
     *
     * @throws InvalidArgumentException when the scope names statuses or a finished window.
     */
    public function take(DateTimeImmutable $now, ?ExecutionFilter $scope = null): MetricsSnapshot
    {
        $scope ??= new ExecutionFilter;

        if ($scope->statuses !== null) {
            throw new InvalidArgumentException('A metrics snapshot counts the statuses it names itself; scope it by workflow, tenant, correlation, parent, or creation window.');
        }

        if ($scope->finishedAfter !== null || $scope->finishedBefore !== null) {
            throw new InvalidArgumentException('A metrics snapshot counts unfinished work; a finished window would leave every field zero. Scope it by creation window instead.');
        }

        $attention = $this->repository->countAttention($scope, $now);
        $oldest = $this->attention->oldestUnfinished($scope);

        return new MetricsSnapshot(
            executions: $this->repository->countByWorkflowAndStatus($scope->withStatus(MetricsSnapshot::STATUSES)),
            // Never negative: a row created by a clock slightly ahead of
            // this one is new, not from the future.
            oldestUnfinishedAgeSeconds: $oldest?->createdAt === null
                ? null
                : max(0, $now->getTimestamp() - $oldest->createdAt->getTimestamp()),
            retriesDue: $attention['retries_due'],
            waitsDue: $attention['waits_due'],
            overdueSteps: $attention['overdue'],
            deferred: $attention['deferred'],
            stalledSchedules: count($this->attention->stalledSchedules($scope, $now)),
            takenAt: $now,
        );
    }
}
