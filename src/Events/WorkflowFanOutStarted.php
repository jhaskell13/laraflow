<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A fan-out step has created and delivered every member it declared and
 * is about to park on the join. Fired after the last member's durable
 * write; carries the group's size and the member ids, never their
 * inputs.
 */
readonly class WorkflowFanOutStarted
{
    /**
     * @param  list<int>  $memberIds
     */
    public function __construct(
        public int $executionId,
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public string $step,
        public int $position,
        public int $members,
        public array $memberIds,
    ) {}
}
