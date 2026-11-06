<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

final class Finalize implements Step
{
    use Works;

    public function handle(WorkflowContext $context): mixed
    {
        $approval = $context->get(RequestApproval::SIGNAL);

        return $this->output($context, [
            'decision' => is_array($approval) ? ($approval['decision'] ?? null) : null,
            'worked_ms' => $this->work($context, 20),
        ]);
    }
}
