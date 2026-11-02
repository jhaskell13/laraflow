<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Repositories;

use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Laraflow\Exceptions\Workflow\InvalidIdempotencyKeyException;
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
            // One statement in its own transaction. Outside any other
            // it is a trivial BEGIN/COMMIT, and the marker is as
            // durable as before. Inside an application's transaction
            // it is a savepoint — which PostgreSQL needs, because a
            // failed statement otherwise aborts the whole transaction
            // and the read-back below could not run.
            IdempotencyKeyModel::query()->getConnection()->transaction(function () use ($executionId, $stepPosition, $key, $at): void {
                IdempotencyKeyModel::query()->create([
                    'workflow_execution_id' => $executionId,
                    'step_position' => $stepPosition,
                    'key' => $key,
                    'status' => IdempotencyStatus::Pending->value,
                    'started_at' => $at,
                ]);
            });

            return null;
        } catch (UniqueConstraintViolationException) {
            // Another attempt — or another worker, right now — got
            // there first. Whatever it recorded is the truth; the
            // caller decides what finding it means.
            $existing = $this->find($executionId, $stepPosition, $key);

            if ($existing === null) {
                // The engine refused the insert as a duplicate and the
                // only row it can have matched is one it considers
                // equal to ours: the row is there, just spelled
                // differently. No retry can change that.
                $collided = $this->collatedMatch($executionId, $stepPosition, $key);

                if ($collided !== null) {
                    throw InvalidIdempotencyKeyException::collides($key, $collided);
                }

                // Truly gone — a release() by a concurrent attempt
                // between the refusal and the read. The caller begins
                // again; the resolver bounds how many times.
            }

            return $existing;
        }
    }

    public function settle(
        int $executionId,
        int $stepPosition,
        string $key,
        mixed $value,
        DateTimeImmutable $at
    ): void {
        // Encoded here rather than left to the grammar: a query-builder
        // update applies no casts, and not every engine encodes an
        // array itself (see EloquentExecutionRepository::asJson()).
        $this->pending($executionId, $stepPosition, $key)->update([
            'status' => IdempotencyStatus::Settled->value,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'error_class' => null,
            'settled_at' => $at,
        ]);
    }

    public function fail(
        int $executionId,
        int $stepPosition,
        string $key,
        string $errorClass,
        DateTimeImmutable $at
    ): void {
        $this->pending($executionId, $stepPosition, $key)->update([
            'status' => IdempotencyStatus::Failed->value,
            'error_class' => $errorClass,
            'settled_at' => $at,
        ]);
    }

    public function release(
        int $executionId,
        int $stepPosition,
        string $key
    ): bool {
        // Only a failed record: a pending one is another attempt's
        // live marker, and deleting it would let two workers both run
        // the work the unique key exists to protect.
        return IdempotencyKeyModel::query()
            ->where('workflow_execution_id', $executionId)
            ->where('step_position', $stepPosition)
            ->where('key', $key)
            ->where('status', IdempotencyStatus::Failed->value)
            ->delete() === 1;
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

    /**
     * The record for exactly this key — matched byte for byte in PHP
     * after the read, because MySQL, MariaDB, and SQL Server compare
     * strings case-insensitively and two keys differing only in case
     * are two keys.
     */
    protected function find(int $executionId, int $stepPosition, string $key): ?IdempotencyRecord
    {
        $model = $this->matching($executionId, $stepPosition, $key)
            ->get()
            ->first(fn (IdempotencyKeyModel $model): bool => $model->key === $key);

        return $model === null ? null : $this->toDomain($model);
    }

    /**
     * The key the database matched ours against when it is not ours
     * byte for byte: the collision, for the refusal to name.
     */
    protected function collatedMatch(int $executionId, int $stepPosition, string $key): ?string
    {
        return $this->matching($executionId, $stepPosition, $key)
            ->get()
            ->first(fn (IdempotencyKeyModel $model): bool => $model->key !== $key)
            ?->key;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<IdempotencyKeyModel>
     */
    protected function matching(int $executionId, int $stepPosition, string $key)
    {
        return IdempotencyKeyModel::query()
            ->where('workflow_execution_id', $executionId)
            ->where('step_position', $stepPosition)
            ->where('key', $key);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<IdempotencyKeyModel>
     */
    protected function pending(int $executionId, int $stepPosition, string $key)
    {
        return $this->matching($executionId, $stepPosition, $key)
            ->where('status', IdempotencyStatus::Pending->value);
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
