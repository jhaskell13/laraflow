<?php

namespace Laraflow\Exceptions\Workflow;

class ExecutionAlreadyClaimedException extends WorkflowException
{
    public static function forId(int|string $id): self
    {
        return new self(
            "Workflow execution [{$id}] is running and claimed by another worker. "
            . "It can be reclaimed once its claim is older than the configured stale_after."
        );
    }
}
