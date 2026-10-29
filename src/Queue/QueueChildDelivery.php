<?php

declare(strict_types=1);

namespace Laraflow\Queue;

use Illuminate\Contracts\Container\Container;
use Laraflow\Workflow\ChildDelivery;

/**
 * @internal Default ChildDelivery binding: Laraflow's own dispatcher.
 *
 * The dispatcher is resolved per call rather than injected: the runner
 * carries a ChildDelivery, the dispatcher carries the runner, and eager
 * construction would make that a cycle.
 */
class QueueChildDelivery implements ChildDelivery
{
    public function __construct(protected Container $container) {}

    public function deliver(int|string $executionId): void
    {
        $this->container->make(WorkflowDispatcher::class)->dispatchExecution($executionId);
    }
}
