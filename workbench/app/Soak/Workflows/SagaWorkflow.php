<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Charge;
use Workbench\App\Soak\Workflows\Steps\Reserve;
use Workbench\App\Soak\Workflows\Steps\Ship;

/**
 * Reserve, charge, ship — and when shipping is doomed, undo the charge
 * and the reservation in reverse, each undo retried under this policy.
 */
final class SagaWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            Reserve::class,
            Charge::class,
            Ship::class,
        ];
    }

    public function defaultStepPolicy(): StepPolicy
    {
        return new StepPolicy(maxAttempts: 2, baseDelaySeconds: 1, maxDelaySeconds: 2);
    }
}
