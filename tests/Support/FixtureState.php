<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Policy\FailurePolicy;

class FixtureState
{
    public static function reset(): void
    {
        PolicyWorkflow::$steps = [];
        PolicyWorkflow::$policy = null;
        PolicyWorkflow::$failurePolicy = FailurePolicy::Stop;
        StepOne::$runs = 0;
        StepOne::$beforeReturn = null;
        StepOne::$seenDeadline = null;
        StepTwo::$runs = 0;
        StepTwo::$failTimes = 0;
        StepTwo::$beforeReturn = null;
        StepTwo::$seenIdempotencyKeys = [];
        StepTwo::$seenContextValue = null;
        StepThree::$runs = 0;
        StepThree::$seenContextValueFromStepTwo = null;
        MutableWorkflow::$steps = [];
    }
}
