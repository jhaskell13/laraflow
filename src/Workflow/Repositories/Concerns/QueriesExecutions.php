<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
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
            // whichever direction the filter orders. Only a cursor this
            // repository issued — a bare id — is accepted; anything
            // else is a caller bug, not a page to guess at.
            if (! ctype_digit($cursor)) {
                throw new InvalidArgumentException(
                    "Malformed execution cursor [{$cursor}]: pass back the nextCursor a previous page returned."
                );
            }

            $query->where('id', $filter->oldestFirst ? '>' : '<', (int) $cursor);
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
            nextCursor: $hasMore && $executions !== [] ? (string) end($executions)->id : null,
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

        /** @var \Illuminate\Support\Collection<int, object{workflow_execution_id: int|string, total: int, completed: int}> $rows */
        $rows = StepExecutionModel::query()
            ->selectRaw('workflow_execution_id, count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as completed', [$completed])
            ->whereIn('workflow_execution_id', $ids)
            ->groupBy('workflow_execution_id')
            ->get();

        $progress = [];

        foreach ($rows as $row) {
            $progress[(string) $row->workflow_execution_id] = [
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
            ];
        }

        return $progress;
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
            // Half-open windows: inclusive start, exclusive end.
            ->when($filter->createdAfter !== null, fn (Builder $q) => $q->where('created_at', '>=', $filter->createdAfter))
            ->when($filter->createdBefore !== null, fn (Builder $q) => $q->where('created_at', '<', $filter->createdBefore))
            ->when($filter->finishedAfter !== null, fn (Builder $q) => $q->where('finished_at', '>=', $filter->finishedAfter))
            ->when($filter->finishedBefore !== null, fn (Builder $q) => $q->where('finished_at', '<', $filter->finishedBefore));
    }
}
