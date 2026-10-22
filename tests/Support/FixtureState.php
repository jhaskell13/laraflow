<?php

namespace Laraflow\Tests\Support;

class FixtureState
{
    public static function reset(): void
    {
        StepOne::$runs = 0;
        StepTwo::$runs = 0;
        StepTwo::$failTimes = 0;
        StepTwo::$seenIdempotencyKeys = [];
        StepTwo::$seenContextValue = null;
        MutableWorkflow::$steps = [];
    }
}
