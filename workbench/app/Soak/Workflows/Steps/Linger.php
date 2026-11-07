<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\Step;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowContext;

/**
 * Parks for longer than a minute without holding a worker, so the
 * schedule that runs it is still unfinished at its next tick and
 * overlap suppression has something to suppress.
 */
final class Linger implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        return Wait::untilSeconds(75);
    }
}
