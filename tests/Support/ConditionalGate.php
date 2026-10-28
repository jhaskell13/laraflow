<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\ConditionalStep;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;
use Throwable;

class ConditionalGate implements ConditionalStep, Step
{
    public static bool $open = true;

    public static int $runs = 0;

    public static ?Throwable $throw = null;

    public function shouldRun(WorkflowContext $context): bool
    {
        if (static::$throw !== null) {
            throw static::$throw;
        }

        return static::$open;
    }

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        $context->set('gate', 'opened');

        return ['gate' => true];
    }
}
