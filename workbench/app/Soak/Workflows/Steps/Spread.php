<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\FanOut;
use Laraflow\Workflow\FanOutStep;
use Laraflow\Workflow\Policy\HasStepPolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowContext;
use Workbench\App\Soak\Workflows\MemberWorkflow;

/**
 * Fans out to as many members as the input says, every third of which
 * flakes once before completing, under a policy that lets the join be
 * retried — a retry resumes the members that did not complete rather
 * than creating a second group.
 */
final class Spread extends FanOutStep implements HasStepPolicy
{
    public function stepPolicy(): StepPolicy
    {
        return new StepPolicy(maxAttempts: 3, baseDelaySeconds: 1, maxDelaySeconds: 3);
    }

    public function members(WorkflowContext $context): iterable
    {
        $count = max(1, (int) $context->get('members', 3));

        for ($i = 1; $i <= $count; $i++) {
            yield new FanOut(new MemberWorkflow, [
                'n' => $i,
                'fails' => $i % 3 === 0 ? 1 : 0,
                'marker' => $context->get('marker'),
            ]);
        }
    }
}
