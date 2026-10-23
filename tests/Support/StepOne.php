<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Closure;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

class StepOne implements Step
{
    public static int $runs = 0;

    /**
     * Runs inside handle(), before the step returns and is checkpointed.
     * Lets a test interfere with the world mid-step — for example by
     * letting another worker take the execution over.
     */
    public static ?Closure $beforeReturn = null;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;

        $context->set('one', 'from-step-one');

        if (static::$beforeReturn !== null) {
            (static::$beforeReturn)();
        }

        return ['n' => 1];
    }
}
