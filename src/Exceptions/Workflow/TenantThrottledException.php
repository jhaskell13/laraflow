<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use DateTimeImmutable;
use DateTimeInterface;
use Laraflow\Exceptions\SuggestsRetryDelay;
use Laraflow\Workflow\Tenancy\TenantCeiling;

/**
 * A claim was turned away because the execution's tenant is at a
 * ceiling. Not a failure of the execution — nothing about its status
 * changed, no attempt was recorded — but a refusal to run it *now*: the
 * record carries when delivery is next tried, and the exception
 * suggests the same delay so whoever asked can wait the right amount.
 * See docs/tenancy.md, "What happens at the ceiling".
 */
final class TenantThrottledException extends WorkflowException implements SuggestsRetryDelay
{
    public function __construct(
        string $message,
        public readonly int $executionId,
        public readonly string $workflow,
        public readonly ?string $correlationId,
        public readonly string $tenantId,
        public readonly TenantCeiling $ceiling,
        public readonly int $limit,
        public readonly int $count,
        public readonly DateTimeImmutable $deferredUntil,
        public readonly int $delaySeconds,
    ) {
        parent::__construct($message);
    }

    public static function atCeiling(
        int $executionId,
        string $workflow,
        ?string $correlationId,
        string $tenantId,
        TenantCeiling $ceiling,
        int $limit,
        int $count,
        DateTimeImmutable $deferredUntil,
        int $delaySeconds,
    ): self {
        $what = match ($ceiling) {
            TenantCeiling::Concurrency => sprintf('%d of its %d allowed executions already hold a worker', $count, $limit),
            TenantCeiling::Rate => sprintf('it has claimed %d times this minute against a ceiling of %d', $count, $limit),
        };

        return new self(
            sprintf(
                'Execution [%d] was deferred: tenant [%s] is at its %s ceiling (%s). Delivery is next tried at %s; '
                .'nothing about the execution changed. Raise the ceiling under laraflow.tenants to let more through.',
                $executionId,
                $tenantId,
                $ceiling->value,
                $what,
                $deferredUntil->format('Y-m-d H:i:s'),
            ),
            $executionId,
            $workflow,
            $correlationId,
            $tenantId,
            $ceiling,
            $limit,
            $count,
            $deferredUntil,
            $delaySeconds,
        );
    }

    public function suggestedRetryDelaySeconds(DateTimeInterface $now): int
    {
        return $this->delaySeconds;
    }
}
