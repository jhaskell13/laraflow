<?php

declare(strict_types=1);

namespace Laraflow\Demo;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

/**
 * @internal Ships for the `laraflow:demo` command; never build on it.
 */
final class SendReceipt implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $receipt = sprintf(
            'Receipt: reservation %s paid by charge %s.',
            $context->get('reservation_id'),
            $context->get('charge_id'),
        );

        $context->set('receipt', $receipt);

        return ['receipt_sent' => true];
    }
}
