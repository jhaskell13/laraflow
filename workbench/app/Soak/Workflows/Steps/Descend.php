<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\ConditionalStep;
use Laraflow\Workflow\SubWorkflowStep;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;
use Workbench\App\Soak\Workflows\ChildWorkflow;

/**
 * Nests one level deeper while depth remains, and is durably skipped
 * at the bottom — a sub-workflow step that is also conditional.
 */
final class Descend extends SubWorkflowStep implements ConditionalStep
{
    public function shouldRun(WorkflowContext $context): bool
    {
        return (int) $context->get('depth', 0) > 0;
    }

    public function workflow(WorkflowContext $context): Workflow
    {
        return new ChildWorkflow;
    }

    public function input(WorkflowContext $context): array
    {
        return ['depth' => (int) $context->get('depth', 1) - 1, 'marker' => $context->get('marker')];
    }
}
