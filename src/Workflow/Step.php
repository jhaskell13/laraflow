<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

interface Step
{
    public function handle(WorkflowContext $context): mixed;
}
