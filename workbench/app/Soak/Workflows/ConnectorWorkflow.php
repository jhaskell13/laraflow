<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\CallProvider;
use Workbench\App\Soak\Workflows\Steps\ListPages;

final class ConnectorWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            CallProvider::class,
            ListPages::class,
        ];
    }
}
