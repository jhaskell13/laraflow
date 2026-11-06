<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Charge;
use Workbench\App\Soak\Workflows\Steps\Flaky;
use Workbench\App\Soak\Workflows\Steps\Slow;

final class RetryingWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            Flaky::class,
            Slow::class,
            Charge::class,
        ];
    }
}
