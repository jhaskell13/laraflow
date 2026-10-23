<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Policy;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\Workflow;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Resolves the effective policy for a step: the step's own declaration
 * wins, then the workflow's default, then the configured package
 * default — which, out of the box, disables retries and sets no
 * deadline.
 */
class PolicyResolver
{
    public function __construct(
        protected StepPolicy $default = new StepPolicy,
    ) {}

    public function forStep(Step $step, Workflow $workflow): StepPolicy
    {
        if ($step instanceof HasStepPolicy) {
            return $step->stepPolicy();
        }

        return $workflow->defaultStepPolicy() ?? $this->default;
    }
}
