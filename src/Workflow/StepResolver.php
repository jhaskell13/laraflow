<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Illuminate\Contracts\Container\Container;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Turns a workflow definition into something safe to run: validates the
 * step list before it is persisted, checks a persisted plan against the
 * definition it is about to be run with, and builds individual steps
 * from the container.
 *
 * The counterpart of WorkflowResolver, which does the same job one
 * level up for the workflow class itself. Both exist because durable
 * state outlives the code that wrote it: everything here is the
 * package refusing to guess what changed code meant by an old record.
 */
class StepResolver
{
    public function __construct(
        protected Container $container,
    ) {}

    /**
     * Validate a workflow's step list, returning the plan to persist.
     *
     * The declared return type of Workflow::steps() is a contract for
     * authors, not a guarantee, so the value is treated as untrusted.
     *
     * @return list<class-string<Step>>
     *
     * @throws InvalidWorkflowDefinitionException
     */
    public function plan(Workflow $workflow): array
    {
        return $this->validate($workflow::class, $workflow->steps());
    }

    /**
     * The supplied workflow must be the one the execution was created
     * from: same class, same steps, same order. Otherwise durable state
     * would be silently reinterpreted by different code.
     *
     * @param  StepExecution[]  $stepExecutions
     *
     * @throws WorkflowDefinitionMismatchException
     */
    public function assertMatches(
        Workflow $workflow,
        WorkflowExecution $execution,
        array $stepExecutions
    ): void {
        if ($execution->workflow !== $workflow::class) {
            throw new WorkflowDefinitionMismatchException(
                "Execution [{$execution->id}] was created from [{$execution->workflow}], "
                .'not ['.$workflow::class.'].'
            );
        }

        $definedSteps = $workflow->steps();
        $persistedSteps = array_map(
            fn (StepExecution $step): string => $step->step,
            $stepExecutions
        );

        if ($definedSteps !== $persistedSteps) {
            throw new WorkflowDefinitionMismatchException(
                "The step plan persisted for execution [{$execution->id}] does not match "
                .'the current definition of ['.$workflow::class.']. '
                .'Persisted: ['.implode(', ', $persistedSteps).']. '
                .'Defined: ['.implode(', ', $definedSteps).']. '
                .'The workflow code has changed since this execution was created.'
            );
        }
    }

    /**
     * Build one step from the container.
     *
     * A class that has been removed or no longer implements Step is a
     * deployment problem reported as a definition mismatch, which the
     * runner persists as that step's failure.
     *
     * @throws WorkflowDefinitionMismatchException
     */
    public function make(string $stepClass): Step
    {
        if (! class_exists($stepClass)) {
            throw new WorkflowDefinitionMismatchException(
                "Step class [{$stepClass}] no longer exists."
            );
        }

        $step = $this->container->make($stepClass);

        if (! $step instanceof Step) {
            throw new WorkflowDefinitionMismatchException(
                "Step class [{$stepClass}] does not implement ".Step::class.'.'
            );
        }

        return $step;
    }

    /**
     * Separate from plan() so the step list arrives typed as `mixed`.
     * `Workflow::steps()` declares `list<class-string<Step>>`, but it
     * is user code: trusting that declaration here would remove the
     * very checks this method exists to perform.
     *
     * @param  array<mixed>  $steps
     * @return list<class-string<Step>>
     *
     * @throws InvalidWorkflowDefinitionException
     */
    protected function validate(string $workflow, array $steps): array
    {
        if ($steps === []) {
            throw InvalidWorkflowDefinitionException::noSteps($workflow);
        }

        $plan = [];

        foreach ($steps as $position => $step) {
            if (! is_string($step) || ! class_exists($step) || ! is_subclass_of($step, Step::class)) {
                throw InvalidWorkflowDefinitionException::invalidStep($workflow, $position, $step);
            }

            $plan[] = $step;
        }

        return $plan;
    }
}
