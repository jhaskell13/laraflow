<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Tenancy;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * @internal The default TenantPolicy: `laraflow.tenants.max_concurrent`
 * and `max_claims_per_minute` for every tenant, each replaceable per
 * tenant under `overrides`. Blank means unset, as everywhere; an
 * override replaces only the keys it names.
 */
final class ConfigTenantPolicy implements TenantPolicy
{
    public function __construct(
        private Config $config,
    ) {}

    public function limitsFor(string $tenantId): TenantLimits
    {
        $overrides = $this->config->get('laraflow.tenants.overrides', []);

        if (! is_array($overrides)) {
            throw new InvalidArgumentException('laraflow.tenants.overrides must be an array of tenant id => ceilings.');
        }

        $override = $overrides[$tenantId] ?? [];

        if (! is_array($override)) {
            throw new InvalidArgumentException("laraflow.tenants.overrides.{$tenantId} must be an array of ceilings.");
        }

        return new TenantLimits(
            maxConcurrent: $this->ceiling($override, $tenantId, 'max_concurrent'),
            maxClaimsPerMinute: $this->ceiling($override, $tenantId, 'max_claims_per_minute'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $override
     */
    private function ceiling(array $override, string $tenantId, string $key): ?int
    {
        $value = array_key_exists($key, $override)
            ? $override[$key]
            : $this->config->get("laraflow.tenants.{$key}");

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(
                "laraflow.tenants.{$key}".(array_key_exists($key, $override) ? " (override for [{$tenantId}])" : '')
                .' must be an integer or null, got '.get_debug_type($value).'.'
            );
        }

        return (int) $value;
    }
}
