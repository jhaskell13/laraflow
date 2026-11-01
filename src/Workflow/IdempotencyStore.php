<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;

/**
 * Durable storage for WorkflowContext::remember(): one record per
 * `(execution, key)`, in the same database as the execution so the
 * truth is transactional with it — never a cache.
 *
 * The primitives here are deliberately small; the state machine that
 * decides what a found record means lives in one place
 * (IdempotencyResolver) so a custom store cannot disagree with it.
 * Every implementation must honour two guarantees from
 * docs/idempotency.md: begin() inserts the marker and commits before it
 * returns, and `(execution, key)` is unique so two concurrent begin()
 * calls for the same key cannot both create a record.
 */
interface IdempotencyStore
{
    /**
     * Record that work is beginning under $key, unless a record already
     * exists. Returns null when this call created the pending marker,
     * or the existing record when one was already there — whatever its
     * status — so the caller can decide what finding it means.
     */
    public function begin(
        int $executionId,
        int $stepPosition,
        string $key,
        DateTimeImmutable $at
    ): ?IdempotencyRecord;

    /** The work completed; persist its value and mark the record settled. */
    public function settle(
        int $executionId,
        string $key,
        mixed $value,
        DateTimeImmutable $at
    ): void;

    /** The work threw a known failure; mark the record so the next attempt starts over. */
    public function fail(
        int $executionId,
        string $key,
        string $errorClass,
        DateTimeImmutable $at
    ): void;

    /** Remove a record so the work can begin again. */
    public function release(
        int $executionId,
        string $key
    ): void;

    /** How many distinct keys the execution has recorded. */
    public function count(int $executionId): int;

    /**
     * Every record for one execution, in the order the work began.
     *
     * @return list<IdempotencyRecord>
     */
    public function records(int $executionId): array;
}
