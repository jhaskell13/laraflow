<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use Laraflow\Workflow\ExecutionStatus;

final class ExecutionAlreadyClaimedException extends WorkflowException
{
    /**
     * @param  ExecutionStatus  $status  What the claim holder is doing:
     *                                   running the plan, or undoing it. Both are claimed work
     *                                   and both are reclaimed the same way, so both arrive here.
     */
    public static function forId(int $id, ExecutionStatus $status = ExecutionStatus::Running): self
    {
        return new self(
            "Workflow execution [{$id}] is {$status->value} and claimed by another worker. "
            .'It can be reclaimed once its claim is older than the configured stale_after.'
        );
    }
}
