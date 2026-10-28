<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\SubWorkflowStep;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;

class RecursiveStep extends SubWorkflowStep
{
    public function workflow(WorkflowContext $context): Workflow
    {
        return new RecursiveWorkflow;
    }
}
