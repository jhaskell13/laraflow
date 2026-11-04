<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Tenancy;

use InvalidArgumentException;

/**
 * The ceilings one tenant is held to: how many of its executions may
 * hold a worker at once, and how many claims it may make in a sliding
 * minute. Null means no ceiling of that kind. Nothing is bounded until
 * an application says so (docs/tenancy.md).
 */
final readonly class TenantLimits
{
    public function __construct(
        public ?int $maxConcurrent = null,
        public ?int $maxClaimsPerMinute = null,
    ) {
        if ($maxConcurrent !== null && $maxConcurrent < 1) {
            throw new InvalidArgumentException('A tenant\'s max_concurrent ceiling must be at least 1; use null for no ceiling.');
        }

        if ($maxClaimsPerMinute !== null && $maxClaimsPerMinute < 1) {
            throw new InvalidArgumentException('A tenant\'s max_claims_per_minute ceiling must be at least 1; use null for no ceiling.');
        }
    }

    public static function none(): self
    {
        return new self;
    }

    /** Whether either ceiling applies. */
    public function bounded(): bool
    {
        return $this->maxConcurrent !== null || $this->maxClaimsPerMinute !== null;
    }
}
