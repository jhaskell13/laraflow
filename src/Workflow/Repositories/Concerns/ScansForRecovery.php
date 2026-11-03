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
        ?string $tenantId = null
    ): array {
        return $this->scan($limit, $workflow, $tenantId)
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
        ?string $tenantId = null
    ): array {
        $now = $this->clock->now();

        return $this->scan($limit, $workflow, $tenantId)
            ->where('status', ExecutionStatus::Pending->value)
            ->where('created_at', '<=', $createdBefore)
            // A deferral is delivery, not state: a pending execution a
            // tenant ceiling turned away has its own timer, and finding
            // it here before that timer fires would double the delivery
            // for nothing (docs/tenancy.md).
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('deferred_until')->orWhere('deferred_until', '<=', $now);
            })
            ->orderBy('id')
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }

    public function dueForRetry(
        DateTimeImmutable $dueBefore,
        int $limit = 100,
        ?string $workflow = null,
        ?string $tenantId = null
    ): array {
        return $this->scan($limit, $workflow, $tenantId)
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
        ?string $tenantId = null
    ): array {
        return $this->scan($limit, $workflow, $tenantId)
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
     * The bound and the two scopes every scan shares, added after the
     * status-and-cutoff predicate each scan's index leads with.
     *
     * @return Builder<WorkflowExecutionModel>
     */
    protected function scan(int $limit, ?string $workflow, ?string $tenantId): Builder
    {
        return WorkflowExecutionModel::query()
            ->when($workflow !== null, fn (Builder $query) => $query->where('workflow', $workflow))
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->limit(max(1, $limit));
    }
}
