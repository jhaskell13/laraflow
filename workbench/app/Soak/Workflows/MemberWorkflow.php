<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Finish;
use Workbench\App\Soak\Workflows\Steps\Flaky;

final class MemberWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            Flaky::class,
            Finish::class,
        ];
    }
}
