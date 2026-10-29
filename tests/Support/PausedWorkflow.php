<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Workflow;

class PausedWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepOne::class, PauseStep::class, StepTwo::class];
    }
}
