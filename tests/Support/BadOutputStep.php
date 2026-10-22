<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use stdClass;

class BadOutputStep implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        return new stdClass;
    }
}
