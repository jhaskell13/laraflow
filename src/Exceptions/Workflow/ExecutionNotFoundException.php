<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

final class ExecutionNotFoundException extends WorkflowException
{
    public static function forId(int $id): self
    {
        return new self("Workflow execution [{$id}] was not found.");
    }
}
