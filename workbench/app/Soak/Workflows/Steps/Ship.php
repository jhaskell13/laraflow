<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use RuntimeException;

/**
 * Fails for good when the input says `doom`, which is what turns a saga
 * around: the steps before it are undone in reverse.
 */
final class Ship implements Step
{
    use Works;

    public function handle(WorkflowContext $context): mixed
    {
        $worked = $this->work($context, 30);

        if ($context->get('doom', false) === true) {
            throw new RuntimeException('Simulated carrier refusal.');
        }

        return $this->output($context, ['worked_ms' => $worked]);
    }
}
