<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Workflow\Workflow;

/**
 * The smallest possible member: one step. Used where a test is about
 * the group rather than about what a member does.
 */
class OneStepWorkflow extends Workflow
{
    public function steps(): array
    {
        return [StepOne::class];
    }
}
