<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Workflow;

/**
 * Workflow whose step list, default step policy, and failure policy are
 * set by the test via static state (reset centrally in FixtureState).
 */
class PolicyWorkflow extends Workflow
{
    /** @var list<class-string<\Laraflow\Workflow\Step>> */
    public static array $steps = [];

    public static ?StepPolicy $policy = null;

    public static FailurePolicy $failurePolicy = FailurePolicy::Stop;

    public function steps(): array
    {
        return static::$steps;
    }

    public function defaultStepPolicy(): ?StepPolicy
    {
        return static::$policy;
    }

    public function failurePolicy(): FailurePolicy
    {
        return static::$failurePolicy;
    }
}
