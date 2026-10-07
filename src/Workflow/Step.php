<?php

namespace Laraflow\Workflow;

interface Step
{
    public function handle(WorkflowContext $context): mixed;
}