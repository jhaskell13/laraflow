<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Illuminate\Contracts\Container\Container;
use Laraflow\Exceptions\Workflow\ChildWorkflowFailedException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\TenantThrottledException;
use Laraflow\Exceptions\Workflow\WorkflowDepthExceededException;
use LogicException;

/**
 * The machinery behind SubWorkflowStep: create or reuse the child
 * execution for a parent step, deliver it, and decide whether the parent
 * waits or already has its answer. Public only because a SubWorkflowStep
 * subclass declaring its own constructor must type-hint and forward it;
 * nothing beyond construction is a contract.
 */
final class ChildWorkflows
{
    /**
     * @param  int  $maxDepth  How deep sub-workflows may nest, from the root;
     *                         laraflow.workflows.max_depth, resolved at boot like every other knob.
     */
    public function __construct(
        protected ExecutionRepository $repository,
        protected Container $container,
        protected ChildDelivery $delivery,
        protected int $maxDepth = 8,
    ) {}

    /**
     * @internal The sub-workflow step calls this through its final handle(); public-api.md: its methods beyond construction are not a contract.
     *
     * The parent step's outcome: a Wait on the child's reserved signal,
     * or — when the child already finished, as it does inline on the
     * sync queue driver — the outcome itself, completing or failing the
     * step without a park.
     *
     * @return Wait|array{execution_id: int, status: string}
     *
     * @throws ChildWorkflowFailedException
     * @throws WorkflowDepthExceededException
     */
    public function waitFor(WorkflowContext $context, SubWorkflowStep $step): Wait|array
    {
        $parent = $this->repository->find($context->executionId() ?? 0);
        $stepRow = $this->repository->steps($parent)[$context->stepPosition() ?? -1] ?? null;

        if ($stepRow === null) {
            throw new LogicException('SubWorkflowStep ran outside a workflow execution.');
        }

        $child = $this->childOf($parent, $stepRow);
        $created = false;

        if ($child === null) {
            Nesting::assertRoom($this->repository, $parent, $step::class, $this->maxDepth);
            $created = true;

            $child = $this->runner()->start(
                $step->workflow($context),
                $step->input($context),
                new ExecutionOptions(
                    correlationId: $context->correlationId(),
                    tenantId: $context->tenantId(),
                    parentExecutionId: $parent->id,
                    parentStepExecutionId: $stepRow->id,
                ),
            );
        }

        // An outcome that already stands: a completed child is this
        // step's answer, a cancelled one cannot be re-run and reports
        // itself until an operator intervenes. A *failed* child on a
        // retry of this step is different — its failure was charged to
        // the policy already, and the retry's whole purpose is to
        // resume it from its own checkpoints.
        if ($child->status === ExecutionStatus::Completed) {
            return ['execution_id' => $child->id, 'status' => $child->status->value];
        }

        if ($child->status === ExecutionStatus::Cancelled) {
            throw ChildWorkflowFailedException::forOutcome($stepRow->step, $stepRow->position, $child->id, $child->status->value);
        }

        // Deliver, or re-deliver. On the sync driver this runs the
        // child to an outcome before returning. A child parked on its
        // own wait is not delivered: a worker could do nothing with it,
        // and the delivery would only manufacture a refused claim.
        if (! $child->isWaiting()) {
            try {
                $this->delivery->deliver($child->id);
            } catch (ExecutionNotRunnableException) {
                // It finished between the read above and the delivery —
                // an operator ran it, say. Its outcome is read below.
            } catch (TenantThrottledException) {
                // The sync driver runs the child inside this call, and
                // the child's tenant — the parent's own — is at a
                // ceiling, which a running parent typically occupies by
                // itself. Not a failure of the step: the deferral is
                // recorded on the child, the parent parks on it below,
                // and the heartbeat re-delivers once a slot frees
                // (docs/tenancy.md, "Interactions").
            }

            $child = $this->repository->find($child->id);
        }

        // The refetch closes the sync fast path — and the race where a
        // child finishes before its parent parks and the signal is
        // refused. A completed child is the answer now; a child a
        // *fresh* delivery just failed inline is charged now rather
        // than waiting a heartbeat. A re-delivered child that reads
        // failed is the stale outcome of the attempt already charged —
        // the parent parks, and the signal or heartbeat brings the new
        // one.
        if ($child->status === ExecutionStatus::Completed) {
            return ['execution_id' => $child->id, 'status' => $child->status->value];
        }

        if ($created && $child->status->isFinished()) {
            throw ChildWorkflowFailedException::forOutcome($stepRow->step, $stepRow->position, $child->id, $child->status->value);
        }

        return Wait::forChild($child->id);
    }

    /**
     * The existing child of this exact parent step, if one was ever
     * created — the newest, though there is only ever one: retries
     * resume it rather than spawning siblings.
     */
    protected function childOf(WorkflowExecution $parent, StepExecution $stepRow): ?WorkflowExecution
    {
        // One indexed read by the parent step, not the parent's every
        // child paged through: a parent that also fans out may have a
        // thousand children, none of them this step's.
        return $this->repository->childOfStep($stepRow->id);
    }

    /**
     * Resolved per call: the runner carries a ChildDelivery whose
     * default binding reaches the dispatcher, which carries the runner —
     * eager injection here would close that loop at construction time.
     */
    protected function runner(): WorkflowRunner
    {
        return $this->container->make(WorkflowRunner::class);
    }
}
