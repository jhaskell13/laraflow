<?php

namespace Laraflow\Workflow;

class WorkflowRunner
{
    public function run(Workflow $workflow): WorkflowContext
    {
        $context = new WorkflowContext();

        foreach ($workflow->steps() as $stepClass) {
            $step = app($stepClass);

            $step->handle($context);
        }

        return $context;
    }
}