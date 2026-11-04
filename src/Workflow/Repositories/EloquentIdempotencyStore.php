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
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        $settled = $this->pending($executionId, $stepPosition, $key)->update([
            'status' => IdempotencyStatus::Settled->value,
            'value' => $encoded,
            'error_class' => null,
            'settled_at' => $at,
        ]);

        // The status changes on every match, so the count is a match
        // count on every engine. Zero used to pass unremarked. Zero with
        // a record still there is the contract — not pending any more,
        // the first outcome recorded is kept. Zero with *no* record is
        // the work's outcome about to be lost: its marker went while the
        // work ran (the cap's own release in a concurrent attempt, a
        // hand-deleted row), and the next attempt would find nothing,
        // read it as "never ran", and run the side effect again — the
        // one thing this store exists to prevent (Milestone 10 audit).
        // The work did happen, so that is what is recorded.
        if ($settled === 0 && $this->find($executionId, $stepPosition, $key) === null) {
            $this->restoreSettled($executionId, $stepPosition, $key, $encoded, $at);
        }
    }

    public function fail(
        int $executionId,
        int $stepPosition,
        string $key,
        string $errorClass,
        DateTimeImmutable $at
    ): void {
        // A miss needs nothing, unlike settle()'s: a record still there
        // keeps its first outcome, and a record gone already says what a
        // known failure says — begin again. The work threw; no side
        // effect is being lost.
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
     * Write a settled record for work whose pending marker vanished
     * before its outcome could land on it. Inserted, so the unique key
     * still decides: another attempt that has begun the key since owns
     * it, and its marker — pending, the uncertain state — is the right
     * thing for the next attempt to find, not overwritten here.
     */
    protected function restoreSettled(int $executionId, int $stepPosition, string $key, string $encoded, DateTimeImmutable $at): void
    {
        try {
            IdempotencyKeyModel::query()->getConnection()->transaction(function () use ($executionId, $stepPosition, $key, $encoded, $at): void {
                IdempotencyKeyModel::query()->getConnection()->table((new IdempotencyKeyModel)->getTable())->insert([
                    'workflow_execution_id' => $executionId,
                    'step_position' => $stepPosition,
                    'key' => $key,
                    'status' => IdempotencyStatus::Settled->value,
                    'value' => $encoded,
                    'started_at' => $at,
                    'settled_at' => $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Begun again meanwhile; that record is the truth now.
        }
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
