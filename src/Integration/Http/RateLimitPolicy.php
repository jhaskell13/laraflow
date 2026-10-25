<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use InvalidArgumentException;

/**
 * A ceiling Laraflow holds itself to for one connection: at most
 * `maxRequests` wire attempts per `perSeconds`, counted in the
 * application's cache so every worker shares the budget.
 *
 * This is the proactive side of rate limiting — not sending what the
 * provider would refuse — and it is per connection, so two tenants'
 * accounts never throttle each other. Off unless configured.
 *
 * When the budget is spent, a wait of at most `maxWaitSeconds` is
 * served in-process; a longer one is refused with ThrottledException,
 * which suggests the wait so the step can retry durably.
 */
final readonly class RateLimitPolicy
{
    public function __construct(
        public int $maxRequests,
        public int $perSeconds = 60,
        public int $maxWaitSeconds = 0,
    ) {
        if ($maxRequests < 1) {
            throw new InvalidArgumentException('rate_limit.max must be at least 1.');
        }

        if ($perSeconds < 1) {
            throw new InvalidArgumentException('rate_limit.per must be at least 1 second.');
        }

        if ($maxWaitSeconds < 0) {
            throw new InvalidArgumentException('rate_limit.max_wait cannot be negative.');
        }
    }

    /**
     * Build from a `rate_limit` configuration array over an optional
     * base, blank values meaning "keep the base". Null when nothing
     * configures a ceiling.
     *
     * @param  array<string, mixed>|null  $config
     */
    public static function fromArray(?array $config, ?self $base = null): ?self
    {
        if ($config === null || $config === []) {
            return $base;
        }

        $value = static fn (string $key): mixed => ($config[$key] ?? '') === '' ? null : $config[$key];

        $max = $value('max') ?? $base?->maxRequests;

        if ($max === null) {
            throw new InvalidArgumentException('rate_limit.max is required when a rate limit is configured.');
        }

        return new self(
            maxRequests: (int) $max,
            perSeconds: (int) ($value('per') ?? ($base === null ? 60 : $base->perSeconds)),
            maxWaitSeconds: (int) ($value('max_wait') ?? ($base === null ? 0 : $base->maxWaitSeconds)),
        );
    }
}
