<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use Laraflow\Workflow\Step;

class InvalidWorkflowDefinitionException extends WorkflowException
{
    public static function noSteps(string $workflow): self
    {
        return new self("Workflow [{$workflow}] defines no steps.");
    }

    public static function invalidStep(string $workflow, int|string $position, mixed $step): self
    {
        $description = is_string($step) ? "[{$step}]" : 'a value of type '.get_debug_type($step);

        return new self(sprintf(
            'Workflow [%s] step at position [%s] is %s, which is not a class implementing %s.',
            $workflow,
            $position,
            $description,
            Step::class,
        ));
    }
}
