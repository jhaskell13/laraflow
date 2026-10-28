<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * Creating a child would nest workflows past laraflow.workflows.max_depth.
 * Refused at creation, where the loop is still one exception — mutual
 * recursion between workflow classes would otherwise be discovered as an
 * unbounded tree of executions.
 */
class WorkflowDepthExceededException extends WorkflowException
{
    public static function forDepth(string $workflow, int $depth, int $max): self
    {
        return new self(
            "Creating a child execution of [{$workflow}] would nest workflows {$depth} deep; "
            ."laraflow.workflows.max_depth allows {$max}. Raise the limit if the nesting is intentional."
        );
    }
}
