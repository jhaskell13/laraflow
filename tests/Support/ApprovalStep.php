<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowContext;

class ApprovalStep implements Step
{
    public static int $runs = 0;

    /** Seconds before the signal wait times out; null waits forever. */
    public static ?int $timeoutSeconds = null;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        $context->set('approval_requested', static::$runs);

        $wait = Wait::forSignal('manager-approval');

        return static::$timeoutSeconds === null
            ? $wait
            : $wait->timeoutAfterSeconds(static::$timeoutSeconds);
    }
}
