<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\Policy\HasStepPolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

/**
 * A step with a four-second deadline that usually keeps to it. With
 * `overrun` set, its first attempt ignores the deadline and sleeps past
 * it — which is what makes a worker killed mid-step leave an attempt
 * recovery must classify as timed out rather than merely interrupted.
 */
final class Slow implements HasStepPolicy, Step
{
    use Works;

    public function stepPolicy(): StepPolicy
    {
        return new StepPolicy(maxAttempts: 3, baseDelaySeconds: 1, maxDelaySeconds: 3, timeoutSeconds: 4);
    }

    public function handle(WorkflowContext $context): mixed
    {
        if ($context->get('overrun', false) === true && $context->attempt() === 1) {
            usleep(5_500_000);

            return $this->output($context, ['worked_ms' => 5500, 'overran' => true]);
        }

        return $this->output($context, ['worked_ms' => $this->work($context, (int) $context->get('slow_ms', 300))]);
    }
}
