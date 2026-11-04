<?php

declare(strict_types=1);

namespace Laraflow\Events;

/**
 * A compensation pass finished. `undone` counts the steps undone;
 * `remaining` counts those the pass never reached, which is non-zero
 * only when a compensation's own retries were exhausted and the pass
 * stopped rather than reaching past it.
 */
final readonly class WorkflowCompensated
{
    public function __construct(
        public int $executionId,
        public string $workflow,
        public ?string $correlationId,
        public ?string $tenantId,
        public int $undone,
        public int $remaining,
        public bool $complete,
    ) {}
}
