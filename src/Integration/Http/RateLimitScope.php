<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use InvalidArgumentException;

/**
 * What one client-side rate-limit budget is shared by.
 *
 * `Connection`, the default, is one budget per connection: every
 * execution calling the same account draws on the same ceiling, which
 * is what a provider's per-account quota looks like from here.
 *
 * `Tenant` is one budget per connection *per tenant* of the execution
 * making the call — read from the running execution's durable
 * `tenant_id`, never from the request. For a connection many tenants
 * share (one Slack workspace, one payment account), it keeps a tenant
 * that bursts from spending the ceiling every other tenant is held to.
 * A call made outside any execution, or from an execution created
 * without a tenant, has no tenant to scope by and draws on the
 * connection's budget instead.
 */
enum RateLimitScope: string
{
    case Connection = 'connection';
    case Tenant = 'tenant';

    /**
     * Parse a configured `scope` value, naming the accepted ones when
     * it is neither.
     */
    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $scope = is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;

        if ($scope === null) {
            throw new InvalidArgumentException(
                'rate_limit.scope must be "connection" or "tenant", got '.(is_scalar($value) ? var_export($value, true) : get_debug_type($value)).'.'
            );
        }

        return $scope;
    }
}
