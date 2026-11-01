<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Workflow;

class RememberingWorkflow extends Workflow
{
    public static ?StepPolicy $policy = null;

    /** @var list<class-string<\Laraflow\Workflow\Step>> */
    public static array $steps = [RememberingStep::class];

    public function steps(): array
    {
        return static::$steps;
    }

    public function defaultStepPolicy(): ?StepPolicy
    {
        return static::$policy;
    }
}
