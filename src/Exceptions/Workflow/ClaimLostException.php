<?php

namespace Laraflow\Exceptions\Workflow;

class ClaimLostException extends WorkflowException
{
    public static function forId(int|string $id): self
    {
        return new self(
            "Lost the claim on workflow execution [{$id}]: another worker has reclaimed it. "
            . "No further state for this execution was persisted by this worker."
        );
    }
}
