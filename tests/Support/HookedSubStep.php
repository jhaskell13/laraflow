<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Closure;
use Laraflow\Workflow\SubWorkflowStep;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;

class HookedSubStep extends SubWorkflowStep
{
    /** Runs inside handle(), before the child execution is created. */
    public static ?Closure $beforeCreate = null;

    public function workflow(WorkflowContext $context): Workflow
    {
        return new TwoStepWorkflow;
    }

    public function input(WorkflowContext $context): array
    {
        if (static::$beforeCreate !== null) {
            (static::$beforeCreate)();
        }

        return [];
    }
}
