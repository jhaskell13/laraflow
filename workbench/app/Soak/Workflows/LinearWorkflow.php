<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Finish;
use Workbench\App\Soak\Workflows\Steps\MaybeNotify;
use Workbench\App\Soak\Workflows\Steps\Persist;
use Workbench\App\Soak\Workflows\Steps\Prepare;

final class LinearWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            Prepare::class,
            Persist::class,
            MaybeNotify::class,
            Finish::class,
        ];
    }
}
