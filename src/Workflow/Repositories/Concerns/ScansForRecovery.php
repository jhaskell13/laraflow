<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories\Concerns;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;

/**
 * @internal The bounded, clock-free scans behind laraflow:recover.
 *
 * Each takes its cutoff as an argument rather than reading a clock, so
 * a scan and the claim that follows it cannot disagree about what
 * "stale" or "due" means. All four share one shape — cutoff, limit,
 * workflow, tenant — and one scoping rule, applied below, so the
 * recover command cannot narrow one scan and silently leave another
 * wide open.
 */
trait ScansForRecovery
{
    public function stale(
        DateTimeImmutable $claimedBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $availableBefore = null
    ): array {
        return $this->scan($limit, $workflow, $tenantId, $availableBefore)
            // Compensating is claimed work like running is: a worker
            // that dies mid-pass leaves the same stale claim, and the
            // pass resumes from the record (docs/advanced-workflows.md).
            ->whereIn('status', [ExecutionStatus::Running->value, ExecutionStatus::Compensating->value])
            ->where(function (Builder $query) use ($claimedBefore) {
                $query
                    ->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', $claimedBefore);
            })
            ->orderBy('id')
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }

    public function pendingBefore(
        DateTimeImmutable $createdBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $availableBefore = null
    ): array {
        return $this->scan($limit, $workflow, $tenantId, $availableBefore)
            ->where('status', ExecutionStatus::Pending->value)
            ->where('created_at', '<=', $createdBefore)
            ->orderBy('id')
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }

    public function dueForRetry(
        DateTimeImmutable $dueBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $availableBefore = null
    ): array {
        return $this->scan($limit, $workflow, $tenantId, $availableBefore)
            ->where('status', ExecutionStatus::Retrying->value)
            ->where('next_retry_at', '<=', $dueBefore)
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }

    public function waitingDue(
        DateTimeImmutable $dueBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $availableBefore = null
    ): array {
        return $this->scan($limit, $workflow, $tenantId, $availableBefore)
            ->where('status', ExecutionStatus::Waiting->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $dueBefore)
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }

    /**
     * The bound, the two scopes, and the in-flight window every scan shares, added after the
     * status-and-cutoff predicate each scan's index leads with.
     *
     * @return Builder<WorkflowExecutionModel>
     */
    protected function scan(int $limit, ?string $workflow, ?string $tenantId, ?DateTimeImmutable $availableBefore = null): Builder
    {
        $now = $this->clock->now();

        return WorkflowExecutionModel::query()
            // A delivery already on the queue is in flight, not lost.
            // Without this every recover run re-pushed every due retry,
            // due wait, and old pending that a worker simply had not
            // reached yet — one more duplicate per run for as long as
            // the backlog lasted (Milestone 10 audit). available_at is
            // when the last push became deliverable (the push instant,
            // or a delayed delivery's due time); a row is re-pushed
            // only once that is older than the redispatch window, the
            // shape EloquentScheduleRepository::redispatchCandidates()
            // uses. A never-pushed row has no mark and is always a
            // candidate. Stale claims pass null: a dead worker's claim
            // is the proof the delivery was consumed.
            ->when($availableBefore !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($availableBefore): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', $availableBefore);
            }))
            ->when($workflow !== null, fn (Builder $query) => $query->where('workflow', $workflow))
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            // A deferral is delivery, not state: an execution a tenant
            // ceiling turned away has its own timer, and every scan that
            // found it before that timer fired would start one more job
            // that can only be turned away again — copies that accumulate
            // for exactly as long as the ceiling holds (docs/tenancy.md).
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('deferred_until')->orWhere('deferred_until', '<=', $now);
            })
            ->limit(max(1, $limit));
    }
}
