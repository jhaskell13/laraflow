<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Workflow;

class FanOutWorkflow extends Workflow
{
    public static ?StepPolicy $policy = null;

    public function steps(): array
    {
        return [StepOne::class, NotifyMembersStep::class];
    }

    public function defaultStepPolicy(): ?StepPolicy
    {
        return static::$policy;
    }
}
