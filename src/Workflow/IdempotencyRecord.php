<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;

/**
 * Read-only record of one piece of idempotent work an execution began.
 * Application code reads these through IdempotencyStore::records();
 * `value` is present only when `status` is settled.
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public int $executionId,
        public int $stepPosition,
        public string $key,
        public IdempotencyStatus $status,
        public mixed $value,
        public ?string $errorClass,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $settledAt,
    ) {}
}
