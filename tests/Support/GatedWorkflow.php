<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Workflow;

class GatedWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepOne::class, ConditionalGate::class, StepTwo::class];
    }
}
