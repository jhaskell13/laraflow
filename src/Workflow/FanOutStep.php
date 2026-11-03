<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * A step that runs many workflows in parallel as child executions and
 * parks until all of them finish.
 *
 * Composition, widened: each member is a child execution with the same
 * linkage, depth bound, cancellation cascade, and retention predicates
 * a sub-workflow's child has. The plan is untouched — this is one class
 * at one position. members() is called once, when the step first
 * runs, and what it yields is recorded on the step before any member
 * is created, so the group is a durable fact and a worker that dies
 * part-way through creating it leaves a remainder the next claim can
 * compute. The full contract is docs/advanced-workflows.md.
 *
 * Subclasses declaring their own constructor must forward
 * FanOutWorkflows to parent::__construct(); handle() is final because
 * the orchestration — declare, create, deliver, join — is the
 * machinery's job, not each subclass's.
 */
abstract class FanOutStep implements Step
{
    public function __construct(protected FanOutWorkflows $fanOut) {}

    /**
     * The members: a workflow and its input, one per child execution.
     * An iterable so a large group need not be built in memory at once.
     * Called once; make it stable.
     *
     * @return iterable<FanOut>
     */
    abstract public function members(WorkflowContext $context): iterable;

    /**
     * What to do about a member that does not complete. WaitAll is the
     * only policy that ships.
     */
    public function joinPolicy(): JoinPolicy
    {
        return JoinPolicy::WaitAll;
    }

    final public function handle(WorkflowContext $context): mixed
    {
        return $this->fanOut->waitFor($context, $this);
    }
}
