<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Tenancy;

use Illuminate\Cache\RateLimiter;
use InvalidArgumentException;

/**
 * @internal What the repository's claim consults: the policy for the
 * tenant's ceilings, the cache-backed limiter the rate ceiling is
 * counted in (shared by every worker, best effort like the connector
 * throttle's), and how long a refused execution waits before its
 * delivery is tried again.
 */
final class TenantCeilings
{
    public function __construct(
        private TenantPolicy $policy,
        private RateLimiter $limiter,
        private int $deferralSeconds,
    ) {
        if ($deferralSeconds < 1) {
            throw new InvalidArgumentException('laraflow.tenants.deferral must be at least 1 second.');
        }
    }

    public function limitsFor(string $tenantId): TenantLimits
    {
        return $this->policy->limitsFor($tenantId);
    }

    /**
     * How long execution $id waits: the configured deferral plus a
     * jitter of up to a fifth of it derived from the id, so a tenant's
     * deferred executions do not all wake together and hammer the
     * ceiling in the same instant.
     */
    public function deferralSecondsFor(int $id): int
    {
        $spread = max(1, intdiv($this->deferralSeconds, 5));

        return $this->deferralSeconds + ($id % $spread);
    }

    /** Claims this tenant has made in the sliding minute. */
    public function claimsThisMinute(string $tenantId): int
    {
        return $this->limiter->attempts($this->key($tenantId));
    }

    public function rateCeilingReached(string $tenantId, int $maxClaimsPerMinute): bool
    {
        return $this->limiter->tooManyAttempts($this->key($tenantId), $maxClaimsPerMinute);
    }

    public function recordClaim(string $tenantId): void
    {
        $this->limiter->hit($this->key($tenantId), 60);
    }

    /**
     * Hashed rather than embedded: a tenant id is application-supplied
     * text up to 255 characters, and cache stores have their own ideas
     * about key length and punctuation.
     */
    private function key(string $tenantId): string
    {
        return 'laraflow:tenant-claims:'.hash('sha256', $tenantId);
    }
}
