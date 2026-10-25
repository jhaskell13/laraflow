<?php

declare(strict_types=1);

namespace Workbench\App\Workflows;

use Laraflow\Workflow\Workflow;
use Workbench\App\Workflows\Steps\ChargeCard;
use Workbench\App\Workflows\Steps\ReceiveOrder;
use Workbench\App\Workflows\Steps\ShipOrder;

/**
 * A three-step order workflow whose behaviour is driven entirely by its
 * input, so one workflow class covers every scenario the playground
 * wants to show: work that takes real time, a step that fails once and
 * heals, a step that fails for good, and a step that ignores its own
 * deadline.
 *
 * This lives in workbench/ rather than src/ on purpose. It exists to be
 * watched, and the traffic generator that drives it has no business
 * being installable into an application.
 *
 * It declares no step policy. Retry budgets and deadlines come from
 * `laraflow.retry.*` and `laraflow.timeout.step`, which is how a real
 * application configures them and means the playground demonstrates the
 * actual configuration path rather than a special case. A workflow is
 * also rebuilt from the container on a queue worker, so anything passed
 * to a constructor here would be lost the moment it crossed a process
 * boundary.
 *
 * Recognised input:
 *
 *   work_ms      int   milliseconds of work per step          (default 250)
 *   fail_at      ?int  position of the step that fails        (default none)
 *   fail_mode    string 'once' heals on the next attempt,
 *                       'always' fails terminally             (default 'once')
 *   hang         bool  the failing-position step ignores its
 *                      deadline instead of bounding itself    (default false)
 */
class SimulatedWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            ReceiveOrder::class,
            ChargeCard::class,
            ShipOrder::class,
        ];
    }
}
