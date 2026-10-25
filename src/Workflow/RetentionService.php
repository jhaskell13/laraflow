<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Support\Clock;
use Psr\Clock\ClockInterface;

/**
 * @internal Use the laraflow:prune command; this class is its engine and
 * not yet covered by the compatibility policy.
 *
 * Applies a RetentionPolicy to finished executions.
 *
 * Three guarantees hold across every tier:
 *
 * 1. **Only finished work is eligible.** Every scan and every write
 *    requires a non-null `finished_at` at or before the tier's cutoff,
 *    and a status that has actually finished. `pending`, `running`, and
 *    `retrying` executions are invisible to retention — a scheduled
 *    retry waiting out its backoff is not old work, it is future work.
 *
 * 2. **The guard is re-applied at the write.** A scan reads, then the
 *    write re-checks status and `finished_at` in the same statement, so
 *    an execution claimed between the two is not acted on: claiming
 *    sets `status = running` and clears `finished_at`, and the write
 *    then matches zero rows. This is the same compare-and-set
 *    discipline claiming and cancellation already use, for the same
 *    reason — a `failed` execution is re-executable, so retention is
 *    always racing a possible recovery.
 *
 * 3. **Every tier is bounded and self-limiting.** At most
 *    `policy->limit` executions per tier per run, written in chunks,
 *    and an execution a tier has already handled stops matching that
 *    tier's scan (see RetentionTier).
 *
 * Tier order is deliberate: delete first, then prune attempts, then
 * scrub. Deleting first means the later tiers never do work on rows
 * that are about to disappear.
 */
class RetentionService
{
    protected ClockInterface $clock;

    public function __construct(
        protected ExecutionRepository $repository,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new Clock;
    }

    /**
     * Apply $policy, optionally restricted to one workflow or tenant.
     *
     * A dry run performs every scan and no write, so its counts are
     * what the same invocation would do a moment later — minus any
     * execution that changes status in between, which is exactly the
     * uncertainty a dry run cannot remove.
     */
    public function prune(
        RetentionPolicy $policy,
        bool $dryRun = false,
        ?string $workflow = null,
        ?string $tenantId = null,
    ): RetentionReport {
        $report = new RetentionReport(dryRun: $dryRun);

        if (! $policy->isEnabled()) {
            return $report;
        }

        $now = $this->clock->now();

        // Under a dry run the delete tier removes nothing, so the later
        // tiers would still see rows a real run would have destroyed
        // and report work that would never happen. Carrying the
        // would-be-deleted ids forward keeps a dry run's numbers equal
        // to the run it is predicting, which is the only reason to
        // trust one.
        /** @var list<int|string> $deleted */
        $deleted = [];

        $report = $this->deleteTier($policy, $report, $now, $dryRun, $workflow, $tenantId, $deleted);
        $report = $this->attemptsTier($policy, $report, $now, $dryRun, $workflow, $tenantId, $deleted);
        $report = $this->scrubTier($policy, $report, $now, $dryRun, $workflow, $tenantId, $deleted);

        return $dryRun ? $report->asDryRun() : $report;
    }

    /**
     * Completed and cancelled executions under `delete_after_days`, and
     * failed ones under their own `delete_failed_after_days`.
     *
     * The two run as separate passes with separate cutoffs because they
     * answer different questions. A completed execution is finished
     * work; a failed one is work that could still be recovered, and
     * discarding it must be a decision an operator took on purpose.
     *
     * @param  list<int|string>  $deleted  Receives the ids removed — or, under
     *                                     a dry run, the ids that would have been.
     */
    protected function deleteTier(
        RetentionPolicy $policy,
        RetentionReport $report,
        DateTimeImmutable $now,
        bool $dryRun,
        ?string $workflow,
        ?string $tenantId,
        array &$deleted,
    ): RetentionReport {
        foreach ([false, true] as $failed) {
            $cutoff = $policy->cutoff(RetentionTier::Delete, $now, $failed);

            if ($cutoff === null) {
                continue;
            }

            $statuses = $failed
                ? [ExecutionStatus::Failed]
                : [ExecutionStatus::Completed, ExecutionStatus::Cancelled];

            $candidates = $this->repository->retentionCandidates(
                RetentionTier::Delete,
                $statuses,
                $cutoff,
                $policy->limit,
                $workflow,
                $tenantId,
            );

            $report = $report->with(scanned: count($candidates));

            if ($candidates === [] || $dryRun) {
                foreach ($candidates as $candidate) {
                    $deleted[] = $candidate->id;
                }

                $report = $report->with(
                    deleted: $failed ? 0 : count($candidates),
                    deletedFailed: $failed ? count($candidates) : 0,
                );

                continue;
            }

            foreach ($this->chunks($candidates, $policy->chunk) as $ids) {
                $affected = $this->repository->deleteExecutions($ids, $statuses, $cutoff);

                $report = $report->with(
                    deleted: $failed ? 0 : $affected,
                    deletedFailed: $failed ? $affected : 0,
                    skipped: count($ids) - $affected,
                );
            }
        }

        return $report;
    }

    /**
     * Attempt history of any finished execution past the bound —
     * completed, cancelled, and failed alike.
     *
     * Failed executions are included here but not in scrubbing, and the
     * difference is resumability: the retry budget reads the step row's
     * attempt counter, which this does not touch, while input and
     * context are what a re-execution actually needs.
     *
     * @param  list<int|string>  $deleted  Ids the delete tier accounted for.
     */
    protected function attemptsTier(
        RetentionPolicy $policy,
        RetentionReport $report,
        DateTimeImmutable $now,
        bool $dryRun,
        ?string $workflow,
        ?string $tenantId,
        array $deleted = [],
    ): RetentionReport {
        $cutoff = $policy->cutoff(RetentionTier::PruneAttempts, $now);

        if ($cutoff === null) {
            return $report;
        }

        $statuses = [ExecutionStatus::Completed, ExecutionStatus::Cancelled, ExecutionStatus::Failed];

        $candidates = $this->repository->retentionCandidates(
            RetentionTier::PruneAttempts,
            $statuses,
            $cutoff,
            $policy->limit,
            $workflow,
            $tenantId,
        );

        $candidates = $this->except($candidates, $deleted);

        $report = $report->with(scanned: count($candidates));

        if ($candidates === []) {
            return $report;
        }

        if ($dryRun) {
            // Rows are left at zero: counting them would mean reading
            // the history this run is not touching, and an estimate an
            // operator might act on is worse than an honest absence.
            return $report->with(attemptExecutions: count($candidates));
        }

        foreach ($this->chunks($candidates, $policy->chunk) as $ids) {
            $pruned = $this->repository->pruneAttempts($ids, $statuses, $cutoff);

            $report = $report->with(
                attemptExecutions: $pruned['executions'],
                attemptRows: $pruned['rows'],
                skipped: count($ids) - $pruned['executions'],
            );
        }

        return $report;
    }

    /**
     * Payloads of completed and cancelled executions past the bound.
     *
     * Failed executions are deliberately absent: clearing the input and
     * context of one would leave a record that still advertises itself
     * as re-executable and can no longer be re-executed. That failure
     * would surface much later, as a workflow running against an empty
     * context, and be very hard to trace back to a retention setting.
     *
     * @param  list<int|string>  $deleted  Ids the delete tier accounted for.
     */
    protected function scrubTier(
        RetentionPolicy $policy,
        RetentionReport $report,
        DateTimeImmutable $now,
        bool $dryRun,
        ?string $workflow,
        ?string $tenantId,
        array $deleted = [],
    ): RetentionReport {
        $cutoff = $policy->cutoff(RetentionTier::ScrubPayloads, $now);

        if ($cutoff === null) {
            return $report;
        }

        $statuses = [ExecutionStatus::Completed, ExecutionStatus::Cancelled];

        $candidates = $this->repository->retentionCandidates(
            RetentionTier::ScrubPayloads,
            $statuses,
            $cutoff,
            $policy->limit,
            $workflow,
            $tenantId,
        );

        $candidates = $this->except($candidates, $deleted);

        $report = $report->with(scanned: count($candidates));

        if ($candidates === []) {
            return $report;
        }

        if ($dryRun) {
            return $report->with(scrubbed: count($candidates));
        }

        foreach ($this->chunks($candidates, $policy->chunk) as $ids) {
            $affected = $this->repository->scrubPayloads($ids, $statuses, $cutoff, $now);

            $report = $report->with(
                scrubbed: $affected,
                skipped: count($ids) - $affected,
            );
        }

        return $report;
    }

    /**
     * Drop candidates a dry run has already counted as deleted. A real
     * run needs no filtering: those rows are gone.
     *
     * @param  WorkflowExecution[]  $candidates
     * @param  list<int|string>  $deleted
     * @return list<WorkflowExecution>
     */
    protected function except(array $candidates, array $deleted): array
    {
        if ($deleted === []) {
            return array_values($candidates);
        }

        return array_values(array_filter(
            $candidates,
            fn (WorkflowExecution $execution): bool => ! in_array($execution->id, $deleted, strict: false),
        ));
    }

    /**
     * @param  WorkflowExecution[]  $candidates
     * @return list<list<int|string>>
     */
    protected function chunks(array $candidates, int $size): array
    {
        $ids = array_map(
            fn (WorkflowExecution $execution): int|string => $execution->id,
            $candidates,
        );

        return array_chunk($ids, max(1, $size));
    }
}
