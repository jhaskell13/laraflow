<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories;

use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Laraflow\Workflow\IdempotencyRecord;
use Laraflow\Workflow\IdempotencyStatus;
use Laraflow\Workflow\IdempotencyStore;
use Laraflow\Workflow\Models\IdempotencyKeyModel;

/**
 * @internal Default binding. Swap via the container if needed.
 *
 * Every write here is its own statement, deliberately outside any
 * transaction of Laraflow's: the marker has to be durable before the
 * work it guards runs, and the value has to survive the step failing
 * afterwards. Neither may ride the step checkpoint.
 */
class EloquentIdempotencyStore implements IdempotencyStore
{
    public function begin(
        int $executionId,
        int $stepPosition,
        string $key,
        DateTimeImmutable $at
    ): ?IdempotencyRecord {
        try {
            IdempotencyKeyModel::query()->create([
                'workflow_execution_id' => $executionId,
                'step_position' => $stepPosition,
                'key' => $key,
                'status' => IdempotencyStatus::Pending->value,
                'started_at' => $at,
            ]);

            return null;
        } catch (UniqueConstraintViolationException) {
            // Another attempt — or another worker, right now — got
            // there first. Whatever it recorded is the truth; the
            // caller decides what finding it means.
            $existing = $this->find($executionId, $key);

            // The row was there a moment ago; a delete racing in here
            // is a release() by a concurrent attempt, and the caller
            // retries begin() when it sees null from a found record.
            return $existing === null ? $this->begin($executionId, $stepPosition, $key, $at) : $existing;
        }
    }

    public function settle(
        int $executionId,
        string $key,
        mixed $value,
        DateTimeImmutable $at
    ): void {
        // Encoded here rather than left to the grammar: a query-builder
        // update applies no casts, and not every engine encodes an
        // array itself (see EloquentExecutionRepository::asJson()).
        IdempotencyKeyModel::query()
            ->where('workflow_execution_id', $executionId)
            ->where('key', $key)
            ->update([
                'status' => IdempotencyStatus::Settled->value,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'error_class' => null,
                'settled_at' => $at,
            ]);
    }

    public function fail(
        int $executionId,
        string $key,
        string $errorClass,
        DateTimeImmutable $at
    ): void {
        IdempotencyKeyModel::query()
            ->where('workflow_execution_id', $executionId)
            ->where('key', $key)
            ->update([
                'status' => IdempotencyStatus::Failed->value,
                'error_class' => $errorClass,
                'settled_at' => $at,
            ]);
    }

    public function release(
        int $executionId,
        string $key
    ): void {
        IdempotencyKeyModel::query()
            ->where('workflow_execution_id', $executionId)
            ->where('key', $key)
            ->delete();
    }

    public function count(int $executionId): int
    {
        return IdempotencyKeyModel::query()
            ->where('workflow_execution_id', $executionId)
            ->count();
    }

    public function records(int $executionId): array
    {
        return array_values(IdempotencyKeyModel::query()
            ->where('workflow_execution_id', $executionId)
            ->orderBy('id')
            ->get()
            ->map(fn (IdempotencyKeyModel $model): IdempotencyRecord => $this->toDomain($model))
            ->all());
    }

    protected function find(int $executionId, string $key): ?IdempotencyRecord
    {
        $model = IdempotencyKeyModel::query()
            ->where('workflow_execution_id', $executionId)
            // Matched byte for byte in PHP after the read, as signal
            // names are: MySQL, MariaDB, and SQL Server compare strings
            // case-insensitively, and two keys differing only in case
            // are two keys.
            ->where('key', $key)
            ->get()
            ->first(fn (IdempotencyKeyModel $model): bool => $model->key === $key);

        return $model === null ? null : $this->toDomain($model);
    }

    protected function toDomain(IdempotencyKeyModel $model): IdempotencyRecord
    {
        return new IdempotencyRecord(
            executionId: $model->workflow_execution_id,
            stepPosition: $model->step_position,
            key: $model->key,
            status: IdempotencyStatus::from($model->status),
            value: $model->value,
            errorClass: $model->error_class,
            startedAt: $model->started_at->toImmutable(),
            settledAt: $model->settled_at?->toImmutable(),
        );
    }
}
