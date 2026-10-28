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
        StepOne::$output = null;
        StepOne::$seenDeadline = null;
        StepOne::$seenCorrelationId = null;
        StepOne::$seenTenantId = null;
        StepTwo::$runs = 0;
        StepTwo::$failTimes = 0;
        StepTwo::$beforeReturn = null;
        StepTwo::$beforeRun = null;
        StepTwo::$contextValue = null;
        StepTwo::$seenIdempotencyKeys = [];
        StepTwo::$seenContextValue = null;
        StepTwo::$seenCorrelationId = null;
        StepThree::$runs = 0;
        StepThree::$seenContextValueFromStepTwo = null;
        MutableWorkflow::$steps = [];
        ConditionalGate::$open = true;
        ConditionalGate::$runs = 0;
        ConditionalGate::$throw = null;
        ConnectorStep::$connector = 'crm';
        ConnectorStep::$connection = null;
        ConnectorStep::$runs = 0;
        ThrowingStep::$exception = null;
    }
}
