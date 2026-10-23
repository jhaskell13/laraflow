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
        StepTwo::$runs = 0;
        StepTwo::$failTimes = 0;
        StepTwo::$seenIdempotencyKeys = [];
        StepTwo::$seenContextValue = null;
        MutableWorkflow::$steps = [];
    }
}
