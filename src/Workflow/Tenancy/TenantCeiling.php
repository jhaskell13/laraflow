<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Tenancy;

/**
 * Which of the two ceilings turned a claim away — carried on
 * TenantThrottledException and WorkflowExecutionDeferred.
 */
enum TenantCeiling: string
{
    /** max_concurrent: the tenant's executions holding a worker right now. */
    case Concurrency = 'concurrency';

    /** max_claims_per_minute: the tenant's claims in the sliding minute. */
    case Rate = 'rate';
}
