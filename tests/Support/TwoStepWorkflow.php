<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Workflow;

class TwoStepWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            StepOne::class,
            StepTwo::class,
        ];
    }
}
