<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\Policy\HasStepPolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

/**
 * Fails on its first `fails` attempts and succeeds after, under a
 * retry policy with a budget of four — so inputs of 0 to 2 heal and an
 * input of 4 or more is a terminal failure the load generator chooses
 * deliberately.
 */
final class Flaky implements HasStepPolicy, Step
{
    use Works;

    public function stepPolicy(): StepPolicy
    {
        return new StepPolicy(maxAttempts: 4, baseDelaySeconds: 1, maxDelaySeconds: 4, jitter: 0.3);
    }

    public function handle(WorkflowContext $context): mixed
    {
        $worked = $this->work($context, 40);

        if ($context->attempt() <= (int) $context->get('fails', 0)) {
            throw new RuntimeException(sprintf('Simulated flake on attempt %d.', $context->attempt()));
        }

        return $this->output($context, ['worked_ms' => $worked]);
    }
}
