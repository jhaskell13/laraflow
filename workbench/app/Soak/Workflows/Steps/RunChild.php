<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\SubWorkflowStep;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;
use Workbench\App\Soak\Workflows\ChildWorkflow;

final class RunChild extends SubWorkflowStep
{
    public function workflow(WorkflowContext $context): Workflow
    {
        return new ChildWorkflow;
    }

    public function input(WorkflowContext $context): array
    {
        return ['depth' => (int) $context->get('depth', 1), 'marker' => $context->get('marker')];
    }
}
