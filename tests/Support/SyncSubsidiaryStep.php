<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\SubWorkflowStep;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;

class SyncSubsidiaryStep extends SubWorkflowStep
{
    public function workflow(WorkflowContext $context): Workflow
    {
        return new TwoStepWorkflow;
    }

    public function input(WorkflowContext $context): array
    {
        return ['subsidiary_id' => $context->get('subsidiary_id', 99)];
    }
}
