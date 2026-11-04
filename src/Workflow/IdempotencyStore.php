<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\InvalidIdempotencyKeyException;

/**
 * Durable storage for WorkflowContext::remember(): one record per
 * `(execution, step position, key)`, in the same database as the
 * execution so the truth is transactional with it — never a cache.
 *
 * The primitives here are deliberately small; the state machine that
 * decides what a found record means lives in one place
 * (IdempotencyResolver) so a custom store cannot disagree with it.
 * Every implementation must honour the guarantees in
 * docs/idempotency.md, and `Laraflow\Testing\IdempotencyStoreContract`
 * is the test that holds it to them: begin() commits the marker before
 * it returns; `(execution, position, key)` is unique so two concurrent
 * begin() calls cannot both create a record; settle() and fail() act
 * only on a pending record; release() removes only a failed one.
 */
interface IdempotencyStore
{
    /**
     * Record that work is beginning under $key, unless a record already
     * exists. Returns null when this call created the pending marker,
     * or the existing record when one was already there — whatever its
     * status — so the caller can decide what finding it means.
     *
     * @throws InvalidIdempotencyKeyException when the database treats $key as equal
     *                                        to a differently-spelled key already recorded.
     *
     * @phpstan-impure
     */
    public function begin(
        int $executionId,
        int $stepPosition,
        string $key,
        DateTimeImmutable $at
    ): ?IdempotencyRecord;

    /**
     * The work completed; persist its value and mark the pending record
     * settled. A record that is no longer pending is left as it is. A
     * record that no longer exists at all is written back as settled —
     * the work happened, and a missing record would read as "never ran"
     * to the next attempt — unless another attempt has begun the key
     * since, whose record then stands.
     */
    public function settle(
        int $executionId,
        int $stepPosition,
        string $key,
        mixed $value,
        DateTimeImmutable $at
    ): void;

    /**
     * The work threw a known failure; mark the pending record so the
     * next attempt starts over. A record that is no longer pending is
     * left as it is, and one that no longer exists needs nothing: an
     * absent record already means "begin again".
     */
    public function fail(
        int $executionId,
        int $stepPosition,
        string $key,
        string $errorClass,
        DateTimeImmutable $at
    ): void;

    /**
     * Remove a failed record so the work can begin again. Returns
     * whether a failed record was removed; false means another attempt
     * moved it first, and the caller must read it again rather than
     * assume. Never removes a pending or settled record.
     */
    public function release(
        int $executionId,
        int $stepPosition,
        string $key
    ): bool;

    /** How many distinct keys the execution has recorded. */
    public function count(int $executionId): int;

    /**
     * Every record for one execution, in the order the work began.
     *
     * @return list<IdempotencyRecord>
     */
    public function records(int $executionId): array;
}
