<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Workflow;

class GatedWorkflow extends Workflow
{
    public static FailurePolicy $failurePolicy = FailurePolicy::Stop;

    public function steps(): array
    {
        return [StepOne::class, ConditionalGate::class, StepTwo::class];
    }

    public function failurePolicy(): FailurePolicy
    {
        return static::$failurePolicy;
    }
}
