<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Laraflow\Exceptions\Workflow\ChildWorkflowFailedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\WorkflowDepthExceededException;
use LogicException;

/**
 * @internal The machinery behind SubWorkflowStep: create or reuse the
 * child execution for a parent step, deliver it, and decide whether the
 * parent waits or already has its answer.
 */
class ChildWorkflows
{
    public function __construct(
        protected ExecutionRepository $repository,
        protected Container $container,
        protected ChildDelivery $delivery,
        protected Config $config,
    ) {}

    /**
     * The parent step's outcome: a Wait on the child's reserved signal,
     * or — when the child already finished, as it does inline on the
     * sync queue driver — the outcome itself, completing or failing the
     * step without a park.
     *
     * @return Wait|array{execution_id: int|string, status: string}
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
            $this->assertDepth($parent, $step);
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
            $this->delivery->deliver($child->id);
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
        $cursor = null;

        do {
            $page = $this->repository->list(
                new ExecutionFilter(parentId: $parent->id),
                ExecutionRepository::MAX_PAGE_SIZE,
                $cursor,
            );

            foreach ($page->executions as $candidate) {
                if ($candidate->parentStepExecutionId === $stepRow->id) {
                    return $candidate;
                }
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return null;
    }

    /**
     * Refuse a child that would nest past laraflow.workflows.max_depth,
     * counted by walking the parent links — bounded by the limit itself,
     * so a cycle in the links cannot loop this walk either.
     */
    protected function assertDepth(WorkflowExecution $parent, SubWorkflowStep $step): void
    {
        $max = max(1, (int) ($this->config->get('laraflow.workflows.max_depth') ?: 8));
        $depth = 1;
        $current = $parent;

        while ($current->parentExecutionId !== null && $depth <= $max) {
            $depth++;

            try {
                $current = $this->repository->find($current->parentExecutionId);
            } catch (ExecutionNotFoundException) {
                // An ancestor pruned by retention: the documented
                // trade-off of linking without foreign keys. The chain
                // above it cannot be deeper than what retention already
                // finished, so the walk stops here.
                break;
            }
        }

        if ($depth + 1 > $max) {
            throw WorkflowDepthExceededException::forDepth($step::class, $depth + 1, $max);
        }
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
