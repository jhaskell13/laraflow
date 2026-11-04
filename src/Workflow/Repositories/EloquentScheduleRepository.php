<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Laraflow\Exceptions\Workflow\ScheduleNotFoundException;
use Laraflow\Exceptions\Workflow\TickAlreadyClaimedException;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\MissedTickPolicy;
use Laraflow\Workflow\Models\ScheduleModel;
use Laraflow\Workflow\Models\ScheduleTickModel;
use Laraflow\Workflow\Scheduling\ScheduleDefinition;
use Laraflow\Workflow\Scheduling\ScheduledWorkflow;
use Laraflow\Workflow\Scheduling\ScheduleRepository;
use Laraflow\Workflow\Scheduling\ScheduleTick;
use Laraflow\Workflow\Scheduling\TickOutcome;
use Psr\Clock\ClockInterface;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 */
class EloquentScheduleRepository implements ScheduleRepository
{
    public function __construct(
        protected ClockInterface $clock,
    ) {}

    public function sync(ScheduleDefinition $definition, DateTimeImmutable $now): ScheduledWorkflow
    {
        $schedule = $definition->schedule;

        $declaration = [
            'cron' => $schedule->expression,
            'timezone' => $definition->timezone,
            'missed_tick_policy' => $schedule->missedTickPolicy->value,
            'without_overlapping' => $schedule->withoutOverlapping,
            'tenant_id' => $schedule->tenantId,
            'declared_at' => $now,
        ];

        $model = ScheduleModel::query()->where('workflow', $definition->workflow)->first();

        if ($model === null) {
            try {
                $model = ScheduleModel::query()->create($declaration + [
                    'workflow' => $definition->workflow,
                    // A new schedule never backfills: nothing before the
                    // first synchronisation is due.
                    'last_tick_at' => $now,
                ]);

                return $this->toDomain($model);
            } catch (UniqueConstraintViolationException) {
                // Two schedulers met a new schedule at once; the other's
                // row is this declaration's row too. Fall through to the
                // ordinary update of it.
                $model = ScheduleModel::query()->where('workflow', $definition->workflow)->firstOrFail();
            }
        }

        // Never last_tick_at, never paused_at: the declaration owns the
        // cadence, the row owns the history and the operator's pause.
        ScheduleModel::query()
            ->whereKey($model->id)
            ->update($this->counted($declaration));

        return $this->toDomain(ScheduleModel::query()->findOrFail($model->id));
    }

    public function all(): array
    {
        return array_values(ScheduleModel::query()
            ->orderBy('id')
            ->get()
            ->map(fn (ScheduleModel $model): ScheduledWorkflow => $this->toDomain($model))
            ->all());
    }

    public function find(string $workflow): ?ScheduledWorkflow
    {
        $model = ScheduleModel::query()->where('workflow', $workflow)->first();

        return $model === null ? null : $this->toDomain($model);
    }

    public function claimTick(
        ScheduledWorkflow $schedule,
        DateTimeImmutable $tickAt,
        TickOutcome $outcome,
        ?Closure $start,
        DateTimeImmutable $claimedAt,
        int $missed = 0,
        ?DateTimeImmutable $missedFrom = null,
        ?int $overlappedExecutionId = null,
    ): ScheduleTick {
        try {
            $model = $this->transaction(function () use ($schedule, $tickAt, $outcome, $start, $claimedAt, $missed, $missedFrom, $overlappedExecutionId): ScheduleTickModel {
                // The execution first, the tick second: the tick insert
                // is the claim, and a lost race must roll the execution
                // back with it — which this ordering gets from the
                // transaction for free, and which keeps the tick row
                // insert-only (it is born carrying its execution id).
                $execution = $start === null ? null : $start();

                $tick = ScheduleTickModel::query()->create([
                    'schedule_id' => $schedule->id,
                    'tick_at' => $tickAt,
                    'outcome' => $outcome->value,
                    'workflow_execution_id' => $execution?->id,
                    'missed' => $missed > 0 ? $missed : null,
                    'missed_from' => $missedFrom,
                    'overlapped_execution_id' => $overlappedExecutionId,
                    'created_at' => $claimedAt,
                ]);

                // Forward only: a concurrent claim of a different instant
                // (two schedulers catching up side by side) must not move
                // the high-water mark backwards.
                ScheduleModel::query()
                    ->whereKey($schedule->id)
                    ->where(function (Builder|\Illuminate\Database\Eloquent\Builder $query) use ($tickAt): void {
                        $query->whereNull('last_tick_at')->orWhere('last_tick_at', '<', $tickAt);
                    })
                    ->update($this->counted(['last_tick_at' => $tickAt]));

                return $tick;
            });
        } catch (UniqueConstraintViolationException) {
            throw TickAlreadyClaimedException::for($schedule->workflow, $tickAt);
        }

        return $this->tickToDomain($model);
    }

    public function lastFiredTick(int $scheduleId): ?ScheduleTick
    {
        $model = ScheduleTickModel::query()
            ->where('schedule_id', $scheduleId)
            ->whereNotNull('workflow_execution_id')
            ->orderByDesc('tick_at')
            ->first();

        return $model === null ? null : $this->tickToDomain($model);
    }

    public function lastTick(int $scheduleId): ?ScheduleTick
    {
        $model = ScheduleTickModel::query()
            ->where('schedule_id', $scheduleId)
            ->orderByDesc('tick_at')
            ->first();

        return $model === null ? null : $this->tickToDomain($model);
    }

    public function tickForExecution(int $executionId): ?ScheduleTick
    {
        $model = ScheduleTickModel::query()
            ->where('workflow_execution_id', $executionId)
            ->first();

        return $model === null ? null : $this->tickToDomain($model);
    }

    public function forget(string $workflow): void
    {
        $model = $this->modelFor($workflow);

        $this->transaction(function () use ($model): void {
            ScheduleTickModel::query()->where('schedule_id', $model->id)->delete();
            ScheduleModel::query()->whereKey($model->id)->delete();
        });
    }

    public function redispatchCandidates(DateTimeImmutable $before, int $limit): array
    {
        // Executions the scheduler's own ticks started, read through the
        // tick table so an application's deliberately-held pending work
        // is never touched. "Dispatched before the cutoff" is included
        // beside "never dispatched": recordDispatch lands before the
        // push, so a push that failed leaves a dispatch mark — and the
        // mark advancing on every re-push is what bounds a backlogged
        // queue to one duplicate per window.
        return array_values(ScheduleTickModel::query()
            ->join(
                'laraflow_workflow_executions',
                'laraflow_workflow_executions.id',
                '=',
                'laraflow_schedule_ticks.workflow_execution_id',
            )
            ->where('laraflow_workflow_executions.status', ExecutionStatus::Pending->value)
            ->where('laraflow_workflow_executions.created_at', '<=', $before)
            ->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($before): void {
                $query->whereNull('laraflow_workflow_executions.dispatched_at')
                    ->orWhere('laraflow_workflow_executions.dispatched_at', '<=', $before);
            })
            // A deferral is delivery with its own timer; re-pushing a
            // tenant-deferred execution early only manufactures a claim
            // a ceiling turns away (docs/tenancy.md).
            ->where(function (\Illuminate\Database\Eloquent\Builder $query): void {
                $query->whereNull('laraflow_workflow_executions.deferred_until')
                    ->orWhere('laraflow_workflow_executions.deferred_until', '<=', $this->clock->now());
            })
            ->orderBy('laraflow_workflow_executions.id')
            ->limit($limit)
            ->pluck('laraflow_workflow_executions.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    public function pruneTicks(DateTimeImmutable $before, int $limit): int
    {
        // Keyed deletes, portable everywhere: DELETE ... LIMIT is a
        // MySQL-ism, and an IN-list over ids the scan just read is the
        // retention tiers' own shape.
        $ids = $this->prunableTicks($before)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return ScheduleTickModel::query()->whereIn('id', $ids)->delete();
    }

    public function countTicksBefore(DateTimeImmutable $before): int
    {
        return $this->prunableTicks($before)->count();
    }

    public function pause(string $workflow, DateTimeImmutable $at): ScheduledWorkflow
    {
        $model = $this->modelFor($workflow);

        // Idempotent: a schedule paused twice keeps the original mark.
        ScheduleModel::query()
            ->whereKey($model->id)
            ->whereNull('paused_at')
            ->update($this->counted(['paused_at' => $at]));

        return $this->toDomain(ScheduleModel::query()->findOrFail($model->id));
    }

    public function resume(string $workflow, DateTimeImmutable $at): ScheduledWorkflow
    {
        $model = $this->modelFor($workflow);

        // The jump of last_tick_at is what makes a resume never catch
        // up; guarded on paused_at so resuming a live schedule cannot
        // move its high-water mark.
        ScheduleModel::query()
            ->whereKey($model->id)
            ->whereNotNull('paused_at')
            ->update($this->counted(['paused_at' => null, 'last_tick_at' => $at]));

        return $this->toDomain(ScheduleModel::query()->findOrFail($model->id));
    }

    /**
     * Ticks claimed before the cutoff that the overlap check no longer
     * needs. Age alone was the rule once, and it broke
     * withoutOverlapping: lastFiredTick() is how the scheduler finds
     * the execution to wait on, so pruning a schedule's latest fired
     * tick made a still-running execution invisible and the next tick
     * started beside it (Milestone 10 audit). Kept, therefore:
     *
     * - the latest fired tick of each schedule — no later tick of the
     *   same schedule has started an execution — whatever became of
     *   its execution, because a later skipped tick is not what the
     *   check reads;
     * - any tick whose execution still exists and has not finished,
     *   latest or not: it is live work, and its origin row is what
     *   says a schedule started it.
     *
     * A tick whose execution retention has already removed is history
     * only, and goes once it is not the latest. Both exemptions are
     * correlated subqueries over the (schedule_id, tick_at) unique key
     * and the executions primary key; the outer read is bounded by the
     * caller's limit.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ScheduleTickModel>
     */
    protected function prunableTicks(DateTimeImmutable $before): \Illuminate\Database\Eloquent\Builder
    {
        $ticks = (new ScheduleTickModel)->getTable();

        return ScheduleTickModel::query()
            ->where("{$ticks}.created_at", '<=', $before)
            ->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($ticks): void {
                $query
                    // Skipped ticks started nothing; the check never
                    // reads them as "the previous run".
                    ->whereNull("{$ticks}.workflow_execution_id")
                    ->orWhereExists(function (Builder $later) use ($ticks): void {
                        $later->selectRaw('1')
                            ->from("{$ticks} as later")
                            ->whereColumn('later.schedule_id', "{$ticks}.schedule_id")
                            ->whereColumn('later.tick_at', '>', "{$ticks}.tick_at")
                            ->whereNotNull('later.workflow_execution_id');
                    });
            })
            ->whereNotExists(function (Builder $live) use ($ticks): void {
                $live->selectRaw('1')
                    ->from('laraflow_workflow_executions')
                    ->whereColumn('laraflow_workflow_executions.id', "{$ticks}.workflow_execution_id")
                    ->whereNotIn('laraflow_workflow_executions.status', [
                        ExecutionStatus::Completed->value,
                        ExecutionStatus::Failed->value,
                        ExecutionStatus::Cancelled->value,
                    ]);
            });
    }

    protected function modelFor(string $workflow): ScheduleModel
    {
        $model = ScheduleModel::query()->where('workflow', $workflow)->first();

        if ($model === null) {
            throw ScheduleNotFoundException::forWorkflow($workflow);
        }

        return $model;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function transaction(Closure $callback): mixed
    {
        return ScheduleModel::query()
            ->getConnection()
            ->transaction($callback);
    }

    /**
     * Values for a schedules-table update whose affected-row count must
     * mean "a row matched the WHERE" — the executions table's rule, for
     * the same two engines that count rows changed rather than matched.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function counted(array $values): array
    {
        return $values + ['version' => new Expression('version + 1')];
    }

    protected function toDomain(ScheduleModel $model): ScheduledWorkflow
    {
        return new ScheduledWorkflow(
            id: $model->id,
            workflow: $model->workflow,
            cron: $model->cron,
            timezone: $model->timezone,
            missedTickPolicy: MissedTickPolicy::from($model->missed_tick_policy),
            withoutOverlapping: $model->without_overlapping,
            tenantId: $model->tenant_id,
            lastTickAt: $this->date($model->last_tick_at),
            pausedAt: $this->date($model->paused_at),
            declaredAt: $this->date($model->declared_at),
            createdAt: $this->date($model->created_at),
        );
    }

    protected function tickToDomain(ScheduleTickModel $model): ScheduleTick
    {
        $tickAt = $this->date($model->tick_at);
        assert($tickAt !== null);

        return new ScheduleTick(
            id: $model->id,
            scheduleId: $model->schedule_id,
            tickAt: $tickAt,
            outcome: TickOutcome::from($model->outcome),
            workflowExecutionId: $model->workflow_execution_id,
            missed: $model->missed ?? 0,
            missedFrom: $this->date($model->missed_from),
            overlappedExecutionId: $model->overlapped_execution_id,
            createdAt: $this->date($model->created_at),
        );
    }

    protected function date(?DateTimeInterface $value): ?DateTimeImmutable
    {
        return $value === null ? null : DateTimeImmutable::createFromInterface($value);
    }
}
