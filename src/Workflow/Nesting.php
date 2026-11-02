<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\WorkflowDepthExceededException;

/**
 * @internal How deep an execution sits in a tree of parents, and
 * whether one more level is allowed — asked once by a sub-workflow step
 * for its child and once by a fan-out step for its whole group, since
 * every member is at the same depth. Shared so the two cannot count
 * differently.
 */
final class Nesting
{
    /**
     * Refuse a child that would nest past the limit, counted by walking
     * the parent links — bounded by the limit itself, so a cycle in the
     * links cannot loop this walk either.
     *
     * @throws WorkflowDepthExceededException
     */
    public static function assertRoom(ExecutionRepository $repository, WorkflowExecution $parent, string $step, int $maxDepth): void
    {
        $max = max(1, $maxDepth);
        $depth = 1;
        $current = $parent;

        while ($current->parentExecutionId !== null && $depth <= $max) {
            $depth++;

            try {
                $current = $repository->find($current->parentExecutionId);
            } catch (ExecutionNotFoundException) {
                // An ancestor pruned by retention: the documented
                // trade-off of linking without foreign keys. The chain
                // above it cannot be deeper than what retention already
                // finished, so the walk stops here.
                break;
            }
        }

        if ($depth + 1 > $max) {
            throw WorkflowDepthExceededException::forDepth($step, $depth + 1, $max);
        }
    }
}
