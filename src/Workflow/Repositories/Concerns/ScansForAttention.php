<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories\Concerns;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;

/**
 * @internal What needs an operator, listed and counted from one set of
 * predicates.
 *
 * The attention scan lists these rows for laraflow:summary and the
 * dashboard's overview; the metrics snapshot counts them
 * (docs/metrics.md). Both go through the predicates below and nothing
 * else, so a gauge and the summary cannot disagree about what "a retry
 * is due" means — only about how many rows one of them shows.
 *
 * Every read here is scoped by the whole filter, as every other number
 * on the summary is. The recovery scans (ScansForRecovery) are scoped
 * by workflow and tenant only, because recovery takes no other filter;
 * the attention scan used to borrow them and so ignored --correlation,
 * --since, and --until for due retries and waits (Milestone 11).
 */
trait ScansForAttention
{
    public function retriesDue(ExecutionFilter $filter, DateTimeImmutable $now, int $limit = 100): array
    {
        return array_values($this->attentionQuery($filter, $now)
            ->tap(fn (Builder $query) => $this->whereRetryDue($query, $now))
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->limit(min(max(1, $limit), self::MAX_PAGE_SIZE))
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all());
    }

    public function waitsDue(ExecutionFilter $filter, DateTimeImmutable $now, int $limit = 100): array
    {
        return array_values($this->attentionQuery($filter, $now)
            ->tap(fn (Builder $query) => $this->whereWaitDue($query, $now))
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->limit(min(max(1, $limit), self::MAX_PAGE_SIZE))
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all());
    }

    public function countAttention(ExecutionFilter $filter, DateTimeImmutable $now): array
    {
        // Four counts, four queries, whatever the size of the table:
        // each is a COUNT over an indexed predicate, never a read of the
        // rows it counts.
        return [
            'retries_due' => $this->attentionQuery($filter, $now)
                ->tap(fn (Builder $query) => $this->whereRetryDue($query, $now))
                ->count(),
            'waits_due' => $this->attentionQuery($filter, $now)
                ->tap(fn (Builder $query) => $this->whereWaitDue($query, $now))
                ->count(),
            'overdue' => $this->filtered($filter->withStatus(null))
                ->tap(fn (Builder $query) => $this->whereStepOverdue($query, $now))
                ->count(),
            'deferred' => $this->filtered($filter->withStatus(null))
                ->tap(fn (Builder $query) => $this->whereDeferred($query, $now))
                ->count(),
        ];
    }

    public function countByWorkflowAndStatus(ExecutionFilter $filter): array
    {
        /** @var \Illuminate\Support\Collection<int, object{workflow: string, status: string, total: int}> $rows */
        $rows = $this->filtered($filter)
            ->selectRaw('workflow, status, count(*) as total')
            ->groupBy('workflow', 'status')
            ->toBase()
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->workflow][$row->status] = (int) $row->total;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * The filter's scope with its statuses left to the predicate, and
     * without the executions a tenant ceiling is holding: a deferred
     * execution has its own timer and is reported as deferred, not as
     * due — the rule the recovery scans follow too.
     *
     * @return Builder<WorkflowExecutionModel>
     */
    protected function attentionQuery(ExecutionFilter $filter, DateTimeImmutable $now): Builder
    {
        return $this->filtered($filter->withStatus(null))
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('deferred_until')->orWhere('deferred_until', '<=', $now);
            });
    }

    /**
     * A scheduled retry whose backoff has elapsed. A null due time, which
     * the runner never writes, is never reported due — here or by the
     * recovery scan, which has always shared this rule; only a claim
     * treats one as due, so an operator's laraflow:execute still runs it.
     *
     * @param  Builder<WorkflowExecutionModel>  $query
     */
    protected function whereRetryDue(Builder $query, DateTimeImmutable $dueBefore): void
    {
        $query->where('status', ExecutionStatus::Retrying->value)
            ->where('next_retry_at', '<=', $dueBefore);
    }

    /**
     * A wait whose resume time or deadline has passed. An indefinite
     * signal wait has no due time and is never due: only a signal ends it.
     *
     * @param  Builder<WorkflowExecutionModel>  $query
     */
    protected function whereWaitDue(Builder $query, DateTimeImmutable $dueBefore): void
    {
        $query->where('status', ExecutionStatus::Waiting->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $dueBefore);
    }

    /**
     * Claimed work — running, or compensating, whose undo is a step like
     * any other — with a running step past its deadline. Counted once per
     * execution, as the attention scan lists it, however many of its
     * steps are late. AttentionScan::isOverdue() is the same rule over a
     * step already read.
     *
     * @param  Builder<WorkflowExecutionModel>  $query
     */
    protected function whereStepOverdue(Builder $query, DateTimeImmutable $now): void
    {
        $executions = $query->getModel()->getTable();
        $steps = (new StepExecutionModel)->getTable();

        $query->whereIn('status', [ExecutionStatus::Running->value, ExecutionStatus::Compensating->value])
            ->whereExists(function (QueryBuilder $step) use ($steps, $executions, $now): void {
                $step->from($steps)
                    ->whereColumn("{$steps}.workflow_execution_id", "{$executions}.id")
                    ->where("{$steps}.status", StepStatus::Running->value)
                    ->whereNotNull("{$steps}.timeout_at")
                    ->where("{$steps}.timeout_at", '<=', $now);
            });
    }

    /**
     * Held by a tenant ceiling past $now. Terminal rows cannot be
     * deferred, but a deferred pending execution an operator then
     * cancelled still carries its stamp; what this answers is "what is a
     * ceiling holding", and nothing holds finished work.
     *
     * @param  Builder<WorkflowExecutionModel>  $query
     */
    protected function whereDeferred(Builder $query, DateTimeImmutable $now): void
    {
        $query->where('deferred_until', '>', $now)
            ->whereNotIn('status', [ExecutionStatus::Completed->value, ExecutionStatus::Cancelled->value]);
    }
}
