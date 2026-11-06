<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Illuminate\Contracts\Container\Container;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowFanOutStarted;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\FanOutFailedException;
use Laraflow\Exceptions\Workflow\FanOutTooWideException;
use Laraflow\Exceptions\Workflow\WorkflowDepthExceededException;
use Laraflow\Support\DurableValue;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The machinery behind FanOutStep: declare the members once, create
 * them in a resumable way, deliver them, and decide whether the step
 * parks on its join or already has its answer. Public only because a
 * FanOutStep subclass declaring its own constructor must type-hint and
 * forward it; nothing beyond construction is a contract.
 *
 * Composition widened, not a new mechanism: a member is a child
 * execution with everything a sub-workflow's child has. What this
 * class answers for is cardinality — a group may be a thousand wide,
 * and a worker may die anywhere while creating it (docs/advanced-
 * workflows.md, "Fan-out and fan-in").
 */
final class FanOutWorkflows
{
    /**
     * @param  int  $maxDepth  laraflow.workflows.max_depth, as for sub-workflows.
     * @param  int  $maxMembers  laraflow.workflows.max_members: the widest group one step may declare.
     */
    public function __construct(
        protected ExecutionRepository $repository,
        protected Container $container,
        protected ChildDelivery $delivery,
        protected EventEmitter $events,
        protected LoggerInterface $logger,
        protected int $maxDepth = 8,
        protected int $maxMembers = 1000,
    ) {}

    /**
     * @internal The fan-out step calls this through its final handle(); public-api.md: its methods beyond construction are not a contract.
     *
     * The step's outcome: a Wait on the join, or — when every member
     * already finished, as they do inline on the sync queue driver —
     * the outcome itself, completing or failing the step without a park.
     *
     * @return Wait|array{total: int, completed: int, members: list<int>}
     *
     * @throws FanOutFailedException
     * @throws FanOutTooWideException
     * @throws WorkflowDepthExceededException
     */
    public function waitFor(WorkflowContext $context, FanOutStep $step): Wait|array
    {
        $parent = $this->repository->find($context->executionId() ?? 0);
        $stepRow = $this->repository->steps($parent)[$context->stepPosition() ?? -1] ?? null;

        if ($stepRow === null) {
            throw new LogicException('FanOutStep ran outside a workflow execution.');
        }

        $group = new FanOutGroup($this->repository, $parent, $stepRow, $step->joinPolicy());
        $progress = $group->progress();
        $declared = $this->declaration($stepRow);

        if ($declared === null) {
            // Members exist but nothing declared them: only this class
            // writes a fan-out's input, so the row has been altered.
            // Refused by name rather than re-read, which would create
            // the group twice.
            if ($progress->total() > 0) {
                throw FanOutFailedException::notDeclared($stepRow->step, $stepRow->position);
            }

            $declared = $this->declare($context, $step, $parent, $stepRow);
        }

        if ($declared === []) {
            // A fan-out over an empty collection — "notify every
            // subscriber", with none — is done, not broken. Parking on
            // a join with no members would wait a whole heartbeat and
            // then fail blaming retention for members that never
            // existed, which is a wrong diagnosis of an ordinary day.
            return ['total' => 0, 'completed' => 0, 'members' => []];
        }

        // Create the remainder, delivering each as it exists. Every
        // member commits on its own, so a worker that dies here leaves
        // exactly the members it made, and the count of them is where
        // the next claim resumes. Each creation is guarded on that
        // count under the step row's lock, so a second worker resuming
        // the same group — a stale reclaim racing the original — finds
        // member $i already made and makes nothing: never created twice.
        $created = $progress->total();

        for ($i = $created; $i < count($declared); $i++) {
            $member = $this->repository->createMember($stepRow->id, $i, fn (): WorkflowExecution => $this->runner()->start(
                $this->workflow($declared[$i]['workflow']),
                $declared[$i]['input'],
                new ExecutionOptions(
                    correlationId: $context->correlationId(),
                    tenantId: $context->tenantId(),
                    parentExecutionId: $parent->id,
                    parentStepExecutionId: $stepRow->id,
                ),
            ));

            if ($member === null) {
                // Another worker made it, and delivers it.
                continue;
            }

            try {
                $this->delivery->deliver($member->id);
            } catch (ExecutionNotRunnableException) {
                // Finished between create and deliver — an operator, or
                // the sync driver through another path. Counted below.
            } catch (Throwable $failure) {
                // On the sync driver the member ran inside this call and
                // its own failure surfaces here. That failure is already
                // durably its own — the runner recorded it before
                // rethrowing — and it is one member's answer, not the
                // group's: the group decides below, from the record. A
                // push that failed on another driver lands here too and
                // leaves the member pending for the heartbeat to
                // re-deliver; said out loud, or a dead queue would be an
                // invisible loop.
                if ($this->repository->find($member->id)->isPending()) {
                    $this->logger->warning('Laraflow could not deliver a fan-out member; the parent heartbeat will re-deliver it.', [
                        'parent_execution_id' => $parent->id,
                        'member_execution_id' => $member->id,
                        'exception' => $failure::class.': '.$failure->getMessage(),
                    ]);
                }
            }
        }

        if ($created < count($declared)) {
            $this->events->emit(new WorkflowFanOutStarted(
                executionId: $parent->id,
                workflow: $parent->workflow,
                correlationId: $parent->correlationId,
                tenantId: $parent->tenantId,
                step: $stepRow->step,
                position: $stepRow->position,
                members: count($declared),
                // A sample, not a census: the event is a notification,
                // and a thousand ids on one is a payload.
                memberIds: $group->memberIds(FanOutGroup::IDS_IN_SUMMARY),
            ));
        } else {
            // Every member already existed, so this is a retry of the
            // step — the empty group returned above, so there is at
            // least one. The members that did not complete, and can be
            // resumed, are handed back to a worker; a failed member
            // resumes from its own checkpoints, the package's ordinary
            // recovery path.
            $group->redeliver($this->delivery, $this->logger);
        }

        // Read back, because on the sync driver the members have already
        // run: a group that is done is the answer now, not a heartbeat
        // from now.
        return $group->outcome($group->progress()) ?? Wait::forJoin($stepRow->id);
    }

    /**
     * Read members() once, validate what it yielded, and record it on
     * the step row before anything is created — the durable fact that
     * makes a partial creation completable.
     *
     * @return list<array{workflow: class-string<Workflow>, input: array<string, mixed>}>
     */
    protected function declare(WorkflowContext $context, FanOutStep $step, WorkflowExecution $parent, StepExecution $stepRow): array
    {
        $declared = $this->read($step->members($context), $stepRow);

        if ($declared !== []) {
            // Every member is at the same depth, so the bound is asked once.
            Nesting::assertRoom($this->repository, $parent, $step::class, $this->maxDepth);
        }

        // Bounded like every durable value — a thousand members' inputs
        // are a payload, and the row they live on has the same ceiling
        // as any other.
        DurableValue::check($declared, 'fan-out declaration');

        $stepRow->declare(['members' => $declared]);
        $this->repository->saveStep($parent, $stepRow);

        return $declared;
    }

    /**
     * Separate from declare() so the yielded members arrive typed as
     * `mixed`: the declared return type of members() is a contract for
     * authors, not a guarantee, and a value that is not a FanOut is
     * refused by name rather than trusted — as StepResolver treats
     * steps().
     *
     * @param  iterable<mixed>  $members
     * @return list<array{workflow: class-string<Workflow>, input: array<string, mixed>}>
     */
    protected function read(iterable $members, StepExecution $stepRow): array
    {
        $declared = [];

        foreach ($members as $member) {
            if (! $member instanceof FanOut) {
                throw FanOutTooWideException::notAMember($stepRow->step, $stepRow->position, get_debug_type($member));
            }

            $declared[] = $member->declaration();

            // Checked as it is read, so a members() that never ends is
            // refused at the bound rather than exhausting memory first.
            if (count($declared) > max(0, $this->maxMembers)) {
                throw FanOutTooWideException::forCount($stepRow->step, $stepRow->position, count($declared), $this->maxMembers);
            }
        }

        return $declared;
    }

    /**
     * What the step row says was declared, or null when nothing was.
     *
     * @return list<array{workflow: class-string<Workflow>, input: array<string, mixed>}>|null
     */
    protected function declaration(StepExecution $stepRow): ?array
    {
        $input = $stepRow->input;

        if (! is_array($input) || ! isset($input['members']) || ! is_array($input['members'])) {
            return null;
        }

        $declared = [];

        foreach ($input['members'] as $member) {
            if (! is_array($member) || ! isset($member['workflow']) || ! is_string($member['workflow'])) {
                return null;
            }

            /** @var class-string<Workflow> $class */
            $class = $member['workflow'];
            /** @var array<string, mixed> $memberInput */
            $memberInput = is_array($member['input'] ?? null) ? $member['input'] : [];

            $declared[] = ['workflow' => $class, 'input' => $memberInput];
        }

        return $declared;
    }

    /**
     * @param  class-string<Workflow>  $class
     */
    protected function workflow(string $class): Workflow
    {
        $workflow = $this->container->make($class);

        if (! $workflow instanceof Workflow) {
            throw new LogicException("Fan-out member class [{$class}] did not resolve to a workflow.");
        }

        return $workflow;
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
