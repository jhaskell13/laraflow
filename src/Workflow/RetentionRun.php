<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;

/**
 * @internal The state of one retention run, carried between its tiers.
 *
 * Holds what every tier needs — the policy, the instant the run began,
 * whether it is a dry run, the scope — so a tier takes one argument
 * instead of seven, and accumulates the report as it goes.
 *
 * It also carries the set of executions the delete tier accounted for.
 * A dry run deletes nothing, so without this the gentler tiers would
 * still see rows a real run would have destroyed and report work that
 * would never happen. A dry run whose numbers differ from the run it
 * predicts is worse than no dry run at all.
 */
class RetentionRun
{
    public RetentionReport $report;

    /**
     * Keyed by id for O(1) lookup, and by string key so an int id and
     * a string id of the same value cannot be treated as different
     * executions — the repository returns whichever the driver gives.
     *
     * @var array<string, true>
     */
    protected array $accounted = [];

    public function __construct(
        public readonly RetentionPolicy $policy,
        public readonly DateTimeImmutable $now,
        public readonly bool $dryRun = false,
        public readonly ?string $workflow = null,
        public readonly ?string $tenantId = null,
    ) {
        $this->report = new RetentionReport(dryRun: $dryRun);
    }

    public function cutoff(RetentionTier $tier, bool $failed = false): ?DateTimeImmutable
    {
        return $this->policy->cutoff($tier, $this->now, $failed);
    }

    /**
     * Record executions the delete tier has handled, so later tiers
     * skip them.
     *
     * @param  WorkflowExecution[]  $executions
     */
    public function accountFor(array $executions): void
    {
        foreach ($executions as $execution) {
            $this->accounted[(string) $execution->id] = true;
        }
    }

    /**
     * The candidates a later tier should still act on.
     *
     * @param  WorkflowExecution[]  $candidates
     * @return list<WorkflowExecution>
     */
    public function remaining(array $candidates): array
    {
        if ($this->accounted === []) {
            return array_values($candidates);
        }

        return array_values(array_filter(
            $candidates,
            fn (WorkflowExecution $execution): bool => ! isset($this->accounted[(string) $execution->id]),
        ));
    }

    public function record(
        int $scanned = 0,
        int $scrubbed = 0,
        int $attemptExecutions = 0,
        int $attemptRows = 0,
        int $deleted = 0,
        int $deletedFailed = 0,
        int $skipped = 0,
    ): void {
        $this->report = $this->report->with(
            scanned: $scanned,
            scrubbed: $scrubbed,
            attemptExecutions: $attemptExecutions,
            attemptRows: $attemptRows,
            deleted: $deleted,
            deletedFailed: $deletedFailed,
            skipped: $skipped,
        );
    }

    /**
     * Ids of $candidates, in writable chunks.
     *
     * @param  WorkflowExecution[]  $candidates
     * @return list<list<int|string>>
     */
    public function chunks(array $candidates): array
    {
        $ids = array_map(
            fn (WorkflowExecution $execution): int|string => $execution->id,
            $candidates,
        );

        return array_chunk($ids, max(1, $this->policy->chunk));
    }
}
