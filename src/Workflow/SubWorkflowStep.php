<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * A step that runs another workflow as a child execution and parks until
 * it finishes.
 *
 * The child is created once, linked to this exact step, dispatched for
 * delivery, and waited on through the reserved `laraflow.child.{id}`
 * signal; its completed/failed/cancelled outcome becomes this step's
 * output or failure. Retrying this step after a failed child
 * re-dispatches the *same* child, which resumes from its own
 * checkpoints. The full contract is docs/advanced-workflows.md.
 *
 * Subclasses declaring their own constructor must forward ChildWorkflows
 * to parent::__construct(); handle() is final because the orchestration
 * — reuse-or-create, depth bound, park — is the machinery's job, not
 * each subclass's.
 */
abstract class SubWorkflowStep implements Step
{
    public function __construct(protected ChildWorkflows $children) {}

    /** The workflow the child execution runs. */
    abstract public function workflow(WorkflowContext $context): Workflow;

    /**
     * The child's input, derived from the parent's context. Identifiers,
     * not payloads: the child's own steps load what they need.
     *
     * @return array<string, mixed>
     */
    public function input(WorkflowContext $context): array
    {
        return [];
    }

    final public function handle(WorkflowContext $context): mixed
    {
        return $this->children->waitFor($context, $this);
    }
}
