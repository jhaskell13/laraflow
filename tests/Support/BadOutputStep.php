<?php

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

class BadOutputStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        return new \stdClass();
    }
}
