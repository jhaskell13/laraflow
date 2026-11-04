<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Laraflow\Workflow\ChildProgress;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionPage;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepAttemptModel;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepAttempt;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @internal The stable read surface: filtering, keyset pagination,
 * counting, and the batched reads that keep a listing from costing one
 * query per row.
 *
 * Every filter criterion maps to an indexed column; adding one without
 * an index in the executions migration is a regression the migration
 * test exists to catch.
 */
trait QueriesExecutions
{
    public function list(
        ExecutionFilter $filter,
        int $limit = 50,
        ?string $cursor = null
    ): ExecutionPage {
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));

        $query = $this->filtered($filter);

        if ($cursor !== null) {
            // Keyset: strictly past the last id of the previous page, in
            // the direction the cursor was issued for.
            $query->where('id', $filter->oldestFirst ? '>' : '<', self::cursorId($cursor, $filter->oldestFirst));
        }

        // Fetch one past the page to learn whether another exists, so
        // the last page never needs a second round trip to discover it
        // is the last.
        $models = $query
            ->orderBy('id', $filter->oldestFirst ? 'asc' : 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $models->count() > $limit;
        $models = $models->take($limit);

        $executions = array_values($models
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all());

        return new ExecutionPage(
            executions: $executions,
            nextCursor: $hasMore && $executions !== [] ? self::issueCursor(end($executions)->id, $filter->oldestFirst) : null,
            limit: $limit,
        );
    }

    public function count(
        ExecutionFilter $filter
    ): int {
        return $this->filtered($filter)->count();
    }

    public function stepProgress(
        array $ids
    ): array {
        if ($ids === []) {
            return [];
        }

        $completed = StepStatus::Completed->value;
        $skipped = StepStatus::Skipped->value;

        /** @var \Illuminate\Support\Collection<int, object{workflow_execution_id: int, total: int, completed: int, skipped: int}> $rows */
        $rows = StepExecutionModel::query()
            ->selectRaw('workflow_execution_id, count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as completed', [$completed])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as skipped', [$skipped])
            ->whereIn('workflow_execution_id', $ids)
            ->groupBy('workflow_execution_id')
            ->get();

        $progress = [];

        foreach ($rows as $row) {
            $progress[$row->workflow_execution_id] = [
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'skipped' => (int) $row->skipped,
            ];
        }

        return $progress;
    }

    public function childProgress(
        int $parentStepExecutionId
    ): ChildProgress {
        /** @var \Illuminate\Support\Collection<int, object{status: string, total: int}> $rows */
        $rows = WorkflowExecutionModel::query()
            ->selectRaw('status, count(*) as total')
            ->where('parent_step_execution_id', $parentStepExecutionId)
            ->groupBy('status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return new ChildProgress($parentStepExecutionId, $counts);
    }

    public function childOfStep(
        int $parentStepExecutionId
    ): ?WorkflowExecution {
        // Straight to the parent_step_execution_id index: the parent's
        // children were once paged through to find this one, which a
        // fan-out a thousand wide elsewhere in the same parent made a
        // thousand-row read on every claim of a sub-workflow step.
        $model = WorkflowExecutionModel::query()
            ->where('parent_step_execution_id', $parentStepExecutionId)
            ->orderByDesc('id')
            ->first();

        return $model === null ? null : $this->toDomain($model);
    }

    public function attemptHistory(
        WorkflowExecution $execution
    ): array {
        // Ordered by the owning step's position rather than by
        // step_execution_id: ids happen to ascend with position because
        // the plan is created in one transaction, but the order a caller
        // sees should rest on the column that means it.
        return StepAttemptModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->orderBy(
                StepExecutionModel::query()
                    ->select('position')
                    ->whereColumn('laraflow_step_executions.id', 'laraflow_step_attempts.step_execution_id')
            )
            ->orderBy('attempt')
            ->get()
            ->map(fn (StepAttemptModel $model): StepAttempt => $this->attemptToDomain($model))
            ->all();
    }

    public function attempts(
        StepExecution $step
    ): array {
        return StepAttemptModel::query()
            ->where('step_execution_id', $step->id)
            ->orderBy('attempt')
            ->get()
            ->map(fn (StepAttemptModel $model): StepAttempt => $this->attemptToDomain($model))
            ->all();
    }

    /**
     * A cursor carries its direction. A bare id said nothing about
     * which way it pointed, so a newest-first cursor handed back with
     * --oldest continued *upward* from a row near the top and silently
     * skipped everything below it (Milestone 10 audit). Oldest-first
     * cursors now carry an `a` prefix; newest-first cursors stay bare
     * digits, so every cursor issued before the change — all of which
     * the dashboard and `laraflow:list` issued newest-first by default
     * — still reads as what it was.
     */
    protected static function issueCursor(int $id, bool $oldestFirst): string
    {
        return ($oldestFirst ? 'a' : '').$id;
    }

    /**
     * The id a cursor this repository issued points past, refusing one
     * issued for the other direction rather than reading it as a page
     * it is not. Anything else is a caller bug, not a page to guess at.
     *
     * @throws InvalidArgumentException
     */
    protected static function cursorId(string $cursor, bool $oldestFirst): int
    {
        $ascending = str_starts_with($cursor, 'a');
        $digits = $ascending ? substr($cursor, 1) : $cursor;

        if ($digits === '' || ! ctype_digit($digits)) {
            throw new InvalidArgumentException(
                "Malformed execution cursor [{$cursor}]: pass back the nextCursor a previous page returned."
            );
        }

        if ($ascending !== $oldestFirst) {
            throw new InvalidArgumentException(sprintf(
                'Execution cursor [%s] was issued for a %s listing and cannot continue a %s one: '
                .'repeat the order the first page was fetched with (--oldest or not), or start again without a cursor.',
                $cursor,
                $ascending ? 'oldest-first' : 'newest-first',
                $oldestFirst ? 'oldest-first' : 'newest-first',
            ));
        }

        return (int) $digits;
    }

    /**
     * The one place a filter becomes SQL, shared by list() and count()
     * so the two can never disagree about what matches. Each criterion
     * maps to an indexed column (see the executions migration); adding
     * one here without an index there is a regression the migration
     * test exists to catch.
     *
     * @return Builder<WorkflowExecutionModel>
     */
    protected function filtered(ExecutionFilter $filter): Builder
    {
        return WorkflowExecutionModel::query()
            ->when($filter->workflows !== null, fn (Builder $q) => $q->whereIn('workflow', $filter->workflows ?? []))
            ->when($filter->statuses !== null, fn (Builder $q) => $q->whereIn(
                'status',
                array_map(fn (ExecutionStatus $status): string => $status->value, $filter->statuses ?? []),
            ))
            ->when($filter->correlationId !== null, fn (Builder $q) => $q->where('correlation_id', $filter->correlationId))
            ->when($filter->tenantId !== null, fn (Builder $q) => $q->where('tenant_id', $filter->tenantId))
            ->when($filter->parentId !== null, fn (Builder $q) => $q->where('parent_execution_id', $filter->parentId))
            // A finished window with no status filter means "finished
            // executions in this window": saying so in SQL lets the
            // (status, finished_at) index serve it — without the status
            // predicate the window alone is a full table scan on every
            // engine, the one shape this filter design exists to forbid.
            ->when(
                $filter->statuses === null && ($filter->finishedAfter !== null || $filter->finishedBefore !== null),
                fn (Builder $q) => $q->whereIn('status', [
                    ExecutionStatus::Completed->value,
                    ExecutionStatus::Failed->value,
                    ExecutionStatus::Cancelled->value,
                ]),
            )
            // Half-open windows: inclusive start, exclusive end.
            ->when($filter->createdAfter !== null, fn (Builder $q) => $q->where('created_at', '>=', $filter->createdAfter))
            ->when($filter->createdBefore !== null, fn (Builder $q) => $q->where('created_at', '<', $filter->createdBefore))
            ->when($filter->finishedAfter !== null, fn (Builder $q) => $q->where('finished_at', '>=', $filter->finishedAfter))
            ->when($filter->finishedBefore !== null, fn (Builder $q) => $q->where('finished_at', '<', $filter->finishedBefore));
    }
}
