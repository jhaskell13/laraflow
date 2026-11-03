<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Tenancy;

/**
 * Answers "what ceilings is this tenant held to?" — consulted on every
 * bounded claim, so a change takes effect on the next one, exactly as
 * a CredentialProvider is consulted per request.
 *
 * The default binding reads `laraflow.tenants`; an application whose
 * tenants are rows rather than configuration entries binds its own and
 * answers from wherever a tenant's plan lives. See docs/tenancy.md.
 */
interface TenantPolicy
{
    public function limitsFor(string $tenantId): TenantLimits;
}
