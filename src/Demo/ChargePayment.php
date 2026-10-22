<?php

declare(strict_types=1);

namespace Laraflow\Demo;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

/**
 * @experimental Ships for the `laraflow:demo` command; not a stable API.
 *
 * Simulates calling a payment provider. With the `fail` input set, the
 * first attempt throws — leaving a failed execution behind so that
 * `laraflow:execute` can demonstrate recovery: the attempt counter and
 * the stable idempotency key both come from durable state.
 */
class ChargePayment implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        if ($context->get('fail', false) === true && $context->attempt() === 1) {
            throw new RuntimeException(
                'Simulated payment gateway timeout on attempt 1. '
                .'Re-execute this workflow to watch it recover.'
            );
        }

        // A real integration would send this key to the provider so a
        // repeated charge after a crash is deduplicated server-side.
        $idempotencyKey = $context->idempotencyKey();

        $chargeId = 'CH-'.substr(hash('sha256', $idempotencyKey), 0, 12);

        $context->set('charge_id', $chargeId);

        return [
            'charge_id' => $chargeId,
            'idempotency_key' => $idempotencyKey,
            'succeeded_on_attempt' => $context->attempt(),
        ];
    }
}
