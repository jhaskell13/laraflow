<?php

declare(strict_types=1);

namespace Laraflow\Demo;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

/**
 * @experimental Ships for the `laraflow:demo` command; not a stable API.
 */
class ReserveInventory implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $sku = $context->get('sku', 'SKU-1001');
        $quantity = $context->get('quantity', 2);

        $reservationId = sprintf('RES-%s-%s', $context->executionId(), $sku);

        $context->set('reservation_id', $reservationId);

        return [
            'reservation_id' => $reservationId,
            'sku' => $sku,
            'quantity' => $quantity,
        ];
    }
}
