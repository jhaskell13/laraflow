<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowContext;

class PauseStep implements Step
{
    public static int $runs = 0;

    public static int $seconds = 600;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        $context->set('paused', true);

        return Wait::untilSeconds(static::$seconds);
    }
}
