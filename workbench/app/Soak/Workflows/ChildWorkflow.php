<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Descend;
use Workbench\App\Soak\Workflows\Steps\Finish;
use Workbench\App\Soak\Workflows\Steps\Prepare;

final class ChildWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            Prepare::class,
            Descend::class,
            Finish::class,
        ];
    }
}
