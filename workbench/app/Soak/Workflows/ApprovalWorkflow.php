<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Finalize;
use Workbench\App\Soak\Workflows\Steps\Pause;
use Workbench\App\Soak\Workflows\Steps\Prepare;
use Workbench\App\Soak\Workflows\Steps\RequestApproval;

final class ApprovalWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            Prepare::class,
            Pause::class,
            RequestApproval::class,
            Finalize::class,
        ];
    }
}
