<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * How the workflow layer hands an execution to whatever runs executions
 * asynchronously — the seam that keeps WorkflowRunner free of queue
 * dependencies while sub-workflows still reach a worker. The default
 * binding adapts Laraflow's queue dispatcher; the sync queue driver
 * makes delivery run in-process.
 */
interface ChildDelivery
{
    public function deliver(int|string $executionId): void;
}
