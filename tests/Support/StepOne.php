<?php

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

class StepOne implements Step
{
    public static int $runs = 0;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;

        $context->set('one', 'from-step-one');

        return ['n' => 1];
    }
}
