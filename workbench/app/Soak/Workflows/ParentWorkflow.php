<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Finish;
use Workbench\App\Soak\Workflows\Steps\Prepare;
use Workbench\App\Soak\Workflows\Steps\RunChild;

final class ParentWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            Prepare::class,
            RunChild::class,
            Finish::class,
        ];
    }
}
