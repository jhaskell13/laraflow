<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowContext;

/**
 * Parks on the `approval` signal the signaller sends — sometimes
 * before this step runs, which the signal inbox keeps — and gives up
 * after a bounded wait, so a run can settle whatever the signaller did.
 */
final class RequestApproval implements Step
{
    public const SIGNAL = 'approval';

    public function handle(WorkflowContext $context): mixed
    {
        return Wait::forSignal(self::SIGNAL)->timeoutAfterSeconds(max(10, (int) $context->get('approval_timeout', 90)));
    }
}
