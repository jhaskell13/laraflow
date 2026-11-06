<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Prepare;
use Workbench\App\Soak\Workflows\Steps\Spread;
use Workbench\App\Soak\Workflows\Steps\Summarize;

final class FanOutWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            Prepare::class,
            Spread::class,
            Summarize::class,
        ];
    }
}
