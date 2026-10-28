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
 *    and a status that has actually finished. `pending`, `running`,
 *    `retrying`, and `waiting` executions are invisible to retention — a scheduled
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
        if (! $policy->isEnabled()) {
            return new RetentionReport(dryRun: $dryRun);
        }

        $run = new RetentionRun($policy, $this->clock->now(), $dryRun, $workflow, $tenantId);

        $this->deleteTier($run);
        $this->attemptsTier($run);
        $this->scrubTier($run);

        return $run->report;
    }

    /**
     * Completed and cancelled executions under `delete_after_days`, and
     * failed ones under their own `delete_failed_after_days`.
     *
     * The two run as separate passes with separate cutoffs because they
     * answer different questions. A completed execution is finished
     * work; a failed one is work that could still be recovered, and
     * discarding it must be a decision an operator took on purpose.
     */
    protected function deleteTier(RetentionRun $run): void
    {
        foreach ([false, true] as $failed) {
            $cutoff = $run->cutoff(RetentionTier::Delete, $failed);

            if ($cutoff === null) {
                continue;
            }

            $statuses = $failed
                ? [ExecutionStatus::Failed]
                : [ExecutionStatus::Completed, ExecutionStatus::Cancelled];

            $candidates = $this->scan($run, RetentionTier::Delete, $statuses, $cutoff);

            // Later tiers skip these whether or not this run wrote
            // anything, which is what keeps a dry run's numbers equal
            // to the run it predicts.
            $run->accountFor($candidates);
            $run->record(scanned: count($candidates));

            if ($candidates === []) {
                continue;
            }

            if ($run->dryRun) {
                $run->record(
                    deleted: $failed ? 0 : count($candidates),
                    deletedFailed: $failed ? count($candidates) : 0,
                );

                continue;
            }

            foreach ($run->chunks($candidates) as $ids) {
                $affected = $this->repository->deleteExecutions($ids, $statuses, $cutoff);

                $run->record(
                    deleted: $failed ? 0 : $affected,
                    deletedFailed: $failed ? $affected : 0,
                    skipped: count($ids) - $affected,
                );
            }
        }
    }

    /**
     * Attempt history of any finished execution past the bound —
     * completed, cancelled, and failed alike.
     *
     * Failed executions are included here but not in scrubbing, and the
     * difference is resumability: the retry budget reads the step row's
     * attempt counter, which this does not touch, while input and
     * context are what a re-execution actually needs.
     */
    protected function attemptsTier(RetentionRun $run): void
    {
        $cutoff = $run->cutoff(RetentionTier::PruneAttempts);

        if ($cutoff === null) {
            return;
        }

        $statuses = [ExecutionStatus::Completed, ExecutionStatus::Cancelled, ExecutionStatus::Failed];
        $candidates = $run->remaining($this->scan($run, RetentionTier::PruneAttempts, $statuses, $cutoff));

        $run->record(scanned: count($candidates));

        if ($candidates === []) {
            return;
        }

        if ($run->dryRun) {
            // Rows are left at zero: counting them would mean reading
            // the history this run is not touching, and an estimate an
            // operator might act on is worse than an honest absence.
            $run->record(attemptExecutions: count($candidates));

            return;
        }

        foreach ($run->chunks($candidates) as $ids) {
            $pruned = $this->repository->pruneAttempts($ids, $statuses, $cutoff);

            $run->record(
                attemptExecutions: $pruned['executions'],
                attemptRows: $pruned['rows'],
                skipped: count($ids) - $pruned['executions'],
            );
        }
    }

    /**
     * Payloads of completed and cancelled executions past the bound.
     *
     * Failed executions are deliberately absent: clearing the input and
     * context of one would leave a record that still advertises itself
     * as re-executable and can no longer be re-executed. That failure
     * would surface much later, as a workflow running against an empty
     * context, and be very hard to trace back to a retention setting.
     */
    protected function scrubTier(RetentionRun $run): void
    {
        $cutoff = $run->cutoff(RetentionTier::ScrubPayloads);

        if ($cutoff === null) {
            return;
        }

        $statuses = [ExecutionStatus::Completed, ExecutionStatus::Cancelled];
        $candidates = $run->remaining($this->scan($run, RetentionTier::ScrubPayloads, $statuses, $cutoff));

        $run->record(scanned: count($candidates));

        if ($candidates === []) {
            return;
        }

        if ($run->dryRun) {
            $run->record(scrubbed: count($candidates));

            return;
        }

        foreach ($run->chunks($candidates) as $ids) {
            $affected = $this->repository->scrubPayloads($ids, $statuses, $cutoff, $run->now);

            $run->record(
                scrubbed: $affected,
                skipped: count($ids) - $affected,
            );
        }
    }

    /**
     * @param  list<ExecutionStatus>  $statuses
     * @return WorkflowExecution[]
     */
    protected function scan(
        RetentionRun $run,
        RetentionTier $tier,
        array $statuses,
        DateTimeImmutable $cutoff,
    ): array {
        return $this->repository->retentionCandidates(
            $tier,
            $statuses,
            $cutoff,
            $run->policy->limit,
            $run->workflow,
            $run->tenantId,
        );
    }
}
