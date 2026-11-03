<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeImmutable;
use Laraflow\Workflow\Tenancy\TenantCeiling;

/**
 * A claim was turned away by a tenant ceiling and the execution's
 * delivery deferred. Fired after the write that records the deferral;
 * the execution's status did not change and no attempt was recorded.
 * Identifiers and numbers only.
 */
readonly class WorkflowExecutionDeferred
{
    /**
     * @param  int  $count  What the ceiling counted — executions holding a
     *                      worker, or claims this minute — against $limit.
     */
    public function __construct(
        public int $executionId,
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public TenantCeiling $ceiling,
        public int $limit,
        public int $count,
        public DateTimeImmutable $deferredUntil,
    ) {}
}
