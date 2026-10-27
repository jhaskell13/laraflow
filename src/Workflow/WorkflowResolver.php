<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Illuminate\Contracts\Container\Container;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Resolves the workflow class recorded on an execution back into an
 * instance, for code paths that only have durable state to go on: queue
 * workers, recovery, and the console commands.
 */
class WorkflowResolver
{
    public function __construct(
        protected Container $container,
    ) {}

    /**
     * @throws WorkflowDefinitionMismatchException when the recorded class no
     *                                             longer exists, is not a workflow, or resolves to something else.
     */
    public function resolve(int|string $executionId, string $class): Workflow
    {
        if (! class_exists($class) || ! is_subclass_of($class, Workflow::class)) {
            throw new WorkflowDefinitionMismatchException(
                "Execution [{$executionId}] was created from [{$class}], which no longer "
                .'exists or is not a workflow. It cannot be executed by this code base.'
            );
        }

        $workflow = $this->container->make($class);

        if (! $workflow instanceof Workflow) {
            throw new WorkflowDefinitionMismatchException(
                "The container resolved [{$class}] to something that is not a ".Workflow::class.'.'
            );
        }

        return $workflow;
    }
}
