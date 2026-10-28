<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Closure;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowContext;

class PauseStep implements Step
{
    public static int $runs = 0;

    public static int $seconds = 600;

    /** Runs before the Wait is returned — inside the step boundary. */
    public static ?Closure $beforeReturn = null;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        $context->set('paused', true);

        if (static::$beforeReturn !== null) {
            (static::$beforeReturn)();
        }

        return Wait::untilSeconds(static::$seconds);
    }
}
