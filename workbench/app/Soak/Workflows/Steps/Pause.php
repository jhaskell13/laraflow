<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowContext;

final class Pause implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        return Wait::untilSeconds(max(1, (int) $context->get('pause', 2)));
    }
}
