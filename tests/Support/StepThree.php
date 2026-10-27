<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

class StepThree implements Step
{
    public static int $runs = 0;

    /** What the failed middle step contributed to the context: nothing. */
    public static mixed $seenContextValueFromStepTwo = null;

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;
        static::$seenContextValueFromStepTwo = $context->get('two');

        $context->set('three', 'from-step-three');

        return ['n' => 3];
    }
}
