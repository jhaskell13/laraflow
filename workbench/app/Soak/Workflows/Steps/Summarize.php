<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

final class Summarize implements Step
{
    use Works;

    public function handle(WorkflowContext $context): mixed
    {
        return $this->output($context, ['worked_ms' => $this->work($context, 30)]);
    }
}
