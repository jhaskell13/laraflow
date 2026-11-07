<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\ConditionalStep;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

/**
 * Skipped durably for the executions whose input says so, so the
 * `skipped` state is in the mix and replayed from the record on every
 * recovery.
 */
final class MaybeNotify implements ConditionalStep, Step
{
    use Works;

    public function shouldRun(WorkflowContext $context): bool
    {
        return $context->get('notify', false) === true;
    }

    public function handle(WorkflowContext $context): mixed
    {
        return $this->output($context, ['worked_ms' => $this->work($context, 20)]);
    }
}
