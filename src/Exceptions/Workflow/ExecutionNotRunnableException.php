<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

use Laraflow\Workflow\ExecutionStatus;

class ExecutionNotRunnableException extends WorkflowException
{
    public static function forStatus(int|string $id, ExecutionStatus $status): self
    {
        return new self(
            "Workflow execution [{$id}] cannot be executed from status [{$status->value}]."
        );
    }
}
