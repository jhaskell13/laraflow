<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories\Concerns;

use DateTimeImmutable;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;

/**
 * @internal The bounded, clock-free scans behind laraflow:recover.
 *
 * Each takes its cutoff as an argument rather than reading a clock, so
 * a scan and the claim that follows it cannot disagree about what
 * "stale" or "due" means.
 */
trait ScansForRecovery
{
    public function stale(
        DateTimeImmutable $claimedBefore,
        int $limit = 100,
        ?string $workflow = null
    ): array {
        return WorkflowExecutionModel::query()
            ->where('status', ExecutionStatus::Running->value)
            ->where(function ($query) use ($claimedBefore) {
                $query
                    ->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', $claimedBefore);
            })
            ->when($workflow !== null, fn ($query) => $query->where('workflow', $workflow))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }

    public function pendingBefore(
        DateTimeImmutable $createdBefore,
        int $limit = 100,
        ?string $workflow = null
    ): array {
        return WorkflowExecutionModel::query()
            ->where('status', ExecutionStatus::Pending->value)
            ->where('created_at', '<=', $createdBefore)
            ->when($workflow !== null, fn ($query) => $query->where('workflow', $workflow))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }

    public function waitingDue(
        DateTimeImmutable $dueBefore,
        int $limit = 100,
        ?string $workflow = null
    ): array {
        return WorkflowExecutionModel::query()
            ->where('status', ExecutionStatus::Waiting->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $dueBefore)
            ->when($workflow !== null, fn ($query) => $query->where('workflow', $workflow))
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
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
        return WorkflowExecutionModel::query()
            ->where('status', ExecutionStatus::Retrying->value)
            ->where('next_retry_at', '<=', $dueBefore)
            ->when($workflow !== null, fn ($query) => $query->where('workflow', $workflow))
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (WorkflowExecutionModel $model) => $this->toDomain($model))
            ->all();
    }
}
