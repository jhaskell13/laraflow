<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\CompensatableStep;
use Laraflow\Workflow\Compensation;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

/**
 * The side effect that must happen once: the charge is made inside
 * remember(), and the step's output is exactly the remembered value —
 * which is what lets the verifier check that an idempotency record and
 * the step that wrote it never disagree.
 *
 * Its undo is remembered too, and with `undo_fails` set the first
 * undo attempt throws, so compensation is retried under the policy.
 */
final class Charge implements CompensatableStep, Step
{
    use Works;

    public function handle(WorkflowContext $context): mixed
    {
        $marker = $this->marker($context);

        return $context->remember('charge', function () use ($context, $marker): array {
            $this->work($context, 40);

            return [
                'charge_id' => sprintf('CH-%d-%s', (int) $context->executionId(), bin2hex(random_bytes(4))),
                'marker' => $marker,
            ];
        });
    }

    public function compensate(Compensation $compensation): void
    {
        if ($compensation->context()->get('undo_fails', false) === true && $compensation->attempt === 1) {
            throw new RuntimeException('Simulated refund gateway outage.');
        }

        $compensation->context()->remember('refund', fn (): array => [
            'refunded' => is_array($compensation->output()) ? ($compensation->output()['charge_id'] ?? null) : null,
            'marker' => $this->marker($compensation->context()),
        ]);
    }
}
