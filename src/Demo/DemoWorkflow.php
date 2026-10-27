<?php

declare(strict_types=1);

namespace Laraflow\Demo;

use Laraflow\Workflow\Workflow;

/**
 * @experimental Ships for the `laraflow:demo` command; not a stable API.
 *
 * A small order-processing workflow that exercises the durable runtime:
 * context passing, persisted outputs, attempt metadata, idempotency keys,
 * and (with the `fail` input) failure plus recovery.
 */
class DemoWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            ReserveInventory::class,
            ChargePayment::class,
            SendReceipt::class,
        ];
    }
}
