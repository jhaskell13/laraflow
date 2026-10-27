<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories\Concerns;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\RetentionTier;

/**
 * @internal The scans and writes behind laraflow:prune.
 *
 * Every method here builds on finishedBefore(), so a scan and the
 * write that follows it can never disagree about which executions are
 * eligible — the guard that stops retention acting on work a worker
 * reclaimed in between.
 */
trait AppliesRetention
{
    public function retentionCandidates(
        RetentionTier $tier,
        array $statuses,
        DateTimeImmutable $finishedBefore,
        int $limit,
        ?string $workflow = null,
        ?string $tenantId = null
    ): array {
        $attempts = (new StepAttemptModel)->getTable();
        $executions = (new WorkflowExecutionModel)->getTable();

        return $this->finishedBefore($statuses, $finishedBefore)
            // Self-limiting predicates. Without these a scheduled run
            // rescans the oldest executions every time, spends its
            // limit on work already done, and never reaches newer rows.
            ->when(
                $tier === RetentionTier::ScrubPayloads,
                fn (Builder $query) => $query->whereNull('payloads_scrubbed_at'),
            )
            ->when(
                $tier === RetentionTier::PruneAttempts,
                fn (Builder $query) => $query->whereExists(
                    fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from($attempts)
                        ->whereColumn("{$attempts}.workflow_execution_id", "{$executions}.id"),
                ),
            )
            ->when($workflow !== null, fn (Builder $query) => $query->where('workflow', $workflow))
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            // Oldest finish first: retention works forward through
            // history, so a run that hits its limit has still made
            // progress at the end that matters.
            ->orderBy('finished_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }

    public function scrubPayloads(
        array $ids,
        array $statuses,
        DateTimeImmutable $finishedBefore,
        DateTimeImmutable $scrubbedAt
    ): int {
        if ($ids === []) {
            return 0;
        }

        return $this->transaction(function () use ($ids, $statuses, $finishedBefore, $scrubbedAt): int {
            // The guarded update runs first and decides which ids are
            // still eligible, so the step wipe below can never touch an
            // execution a worker reclaimed in the meantime.
            $eligible = $this->stillEligible($ids, $statuses, $finishedBefore);

            if ($eligible === []) {
                return 0;
            }

            // counted(): a rescrub writing values that are already
            // null must still report the rows it matched, or the dry
            // run and the real run would disagree on MySQL/MariaDB.
            $scrubbed = $this->finishedBefore($statuses, $finishedBefore)
                ->whereIn('id', $eligible)
                ->update($this->counted([
                    'input' => null,
                    'context' => null,
                    'payloads_scrubbed_at' => $scrubbedAt,
                ]));

            StepExecutionModel::query()
                ->whereIn('workflow_execution_id', $eligible)
                ->update([
                    'input' => null,
                    'output' => null,
                ]);

            return $scrubbed;
        });
    }

    public function pruneAttempts(
        array $ids,
        array $statuses,
        DateTimeImmutable $finishedBefore
    ): array {
        if ($ids === []) {
            return ['executions' => 0, 'rows' => 0];
        }

        return $this->transaction(function () use ($ids, $statuses, $finishedBefore): array {
            $eligible = $this->stillEligible($ids, $statuses, $finishedBefore);

            if ($eligible === []) {
                return ['executions' => 0, 'rows' => 0];
            }

            // The guard is re-applied inside the DELETE itself, not only
            // in the SELECT above: a worker may reclaim one of these
            // executions between the two statements (and under REPEATABLE
            // READ the select's snapshot cannot even see that claim), and
            // a running execution must not lose its attempt history.
            $rows = StepAttemptModel::query()
                ->whereIn('workflow_execution_id', $eligible)
                ->whereExists(
                    fn ($query) => $query
                        ->from('laraflow_workflow_executions')
                        ->whereColumn('laraflow_workflow_executions.id', 'laraflow_step_attempts.workflow_execution_id')
                        ->whereIn('status', array_map(
                            fn (ExecutionStatus $status): string => $status->value,
                            $statuses,
                        ))
                        ->whereNotNull('finished_at')
                        ->where('finished_at', '<=', $finishedBefore),
                )
                ->delete();

            return ['executions' => count($eligible), 'rows' => $rows];
        });
    }

    public function deleteExecutions(
        array $ids,
        array $statuses,
        DateTimeImmutable $finishedBefore
    ): int {
        if ($ids === []) {
            return 0;
        }

        return $this->transaction(function () use ($ids, $statuses, $finishedBefore): int {
            $deleted = $this->finishedBefore($statuses, $finishedBefore)
                ->whereIn('id', $ids)
                ->delete();

            if ($deleted === 0) {
                return 0;
            }

            // Remove children whose parent is now gone, rather than
            // children of the ids we asked about: the guard may have
            // spared some, and those must keep their steps. Expressed
            // as "no surviving parent" so it is correct either way, and
            // whether or not the engine enforced the declared cascade —
            // SQLite does so only when the connection asks.
            $this->deleteOrphansOf(StepAttemptModel::class, $ids);
            $this->deleteOrphansOf(StepExecutionModel::class, $ids);

            return $deleted;
        });
    }

    /**
     * The retention guard, as a query: a status that has actually
     * finished, and a finish at or before the cutoff.
     *
     * Every retention scan and every retention write is built from this
     * one method, so a scan and the write that follows it can never
     * disagree about what "eligible" means. Claiming an execution sets
     * `status = running` and clears `finished_at`, so a reclaimed row
     * fails this predicate and is left alone.
     *
     * @param  list<ExecutionStatus>  $statuses
     * @return Builder<WorkflowExecutionModel>
     */
    protected function finishedBefore(array $statuses, DateTimeImmutable $finishedBefore): Builder
    {
        return WorkflowExecutionModel::query()
            ->whereIn('status', array_map(
                fn (ExecutionStatus $status): string => $status->value,
                $statuses,
            ))
            ->whereNotNull('finished_at')
            ->where('finished_at', '<=', $finishedBefore);
    }

    /**
     * Which of $ids still satisfy the guard, read inside the caller's
     * transaction so the writes that follow act on exactly this set.
     *
     * @param  list<int|string>  $ids
     * @param  list<ExecutionStatus>  $statuses
     * @return list<int|string>
     */
    protected function stillEligible(array $ids, array $statuses, DateTimeImmutable $finishedBefore): array
    {
        /** @var list<int|string> $eligible */
        $eligible = $this->finishedBefore($statuses, $finishedBefore)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        return $eligible;
    }

    /**
     * Delete rows of $model among $ids whose parent execution no longer
     * exists.
     *
     * @param  class-string<StepAttemptModel|StepExecutionModel>  $model
     * @param  list<int|string>  $ids
     */
    protected function deleteOrphansOf(string $model, array $ids): void
    {
        $executions = (new WorkflowExecutionModel)->getTable();
        $child = (new $model)->getTable();

        $model::query()
            ->whereIn('workflow_execution_id', $ids)
            ->whereNotExists(
                fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from($executions)
                    ->whereColumn("{$executions}.id", "{$child}.workflow_execution_id"),
            )
            ->delete();
    }
}
