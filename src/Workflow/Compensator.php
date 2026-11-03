<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowCompensated;
use Laraflow\Events\WorkflowCompensationStarted;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\CompensationFailedException;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\PolicyResolver;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * @internal Runs the reverse pass: undo what completed, newest first.
 *
 * Every compensation is an ordinary step row appended after the plan,
 * so it has its own status, attempts, timeout, retry schedule, output,
 * and error, and appears in `laraflow:show` and the dashboard exactly
 * as a step does — because it is one. Nothing here re-implements
 * retries or history; it borrows the machinery steps already use.
 *
 * The pass runs under the claim that ran the failing step, and it
 * resumes from the record: each compensation's own row says whether it
 * ran, so a worker that dies mid-pass leaves a stale claim the stale
 * scan reclaims and a pass the next claim continues.
 * docs/advanced-workflows.md, "Compensation".
 */
class Compensator
{
    public function __construct(
        private readonly ExecutionRepository $repository,
        private readonly StepResolver $steps,
        private readonly OutcomeRecorder $outcomes,
        private readonly PolicyResolver $policies,
        private readonly EventEmitter $events,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Whether this execution has anything to undo: a completed step
     * whose class declares compensate() and which has not been
     * compensated already.
     *
     * @param  StepExecution[]  $steps
     */
    public function hasWork(array $steps): bool
    {
        return $this->pending($steps) !== [];
    }

    /**
     * Whether a pass is already under way and unfinished: a
     * compensation row that has not completed.
     *
     * This, not the execution's status, is what tells a fresh claim to
     * resume a pass. A compensation whose own retry was scheduled rests
     * the execution as `retrying` — accurately: it *is* retrying, and
     * the existing due-retry scan delivers it with no new mechanism.
     *
     * @param  StepExecution[]  $steps
     */
    public function isUnderWay(array $steps): bool
    {
        foreach ($steps as $row) {
            if ($row->isCompensation() && ! $row->isCompleted()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Undo what completed, in reverse. Returns true when the pass
     * finished — every compensation ran — and false when it stopped at
     * one whose retries were exhausted.
     *
     * The execution is left claimed either way: the caller records the
     * terminal failure, which is what the execution rests as.
     *
     * @param  StepExecution[]  $steps
     *
     * @throws ClaimLostException
     */
    public function compensate(
        WorkflowExecution $execution,
        Workflow $workflow,
        WorkflowContext $context,
        array $steps,
        Throwable $failure,
    ): bool {
        $pending = $this->pending($steps);

        if ($pending === []) {
            return true;
        }

        if (! $execution->isCompensating()) {
            $execution->markCompensating($this->outcomes->errorMessage($failure), $this->outcomes->errorClass($failure));
            $this->repository->checkpointStatus($execution);

            $this->events->emit(new WorkflowCompensationStarted(
                ...$this->outcomes->identity($execution),
                steps: count($pending),
                failure: $this->outcomes->errorMessage($failure),
            ));
        }

        $undone = 0;

        foreach ($pending as $completed) {
            if (! $this->undo($execution, $workflow, $context, $completed, $failure)) {
                $this->events->emit(new WorkflowCompensated(
                    ...$this->outcomes->identity($execution),
                    undone: $undone,
                    remaining: count($pending) - $undone,
                    complete: false,
                ));

                return false;
            }

            $undone++;
        }

        $this->events->emit(new WorkflowCompensated(
            ...$this->outcomes->identity($execution),
            undone: $undone,
            remaining: 0,
            complete: true,
        ));

        return true;
    }

    /**
     * Undo one step. The compensation's row is created first — so a
     * worker that dies inside compensate() leaves evidence that it
     * began, exactly as a step does — then run, then settled.
     *
     * Returns false when this compensation has failed for the last
     * time: the pass stops rather than reaching past it to undo
     * earlier work the failed one may have depended on.
     */
    private function undo(
        WorkflowExecution $execution,
        Workflow $workflow,
        WorkflowContext $context,
        StepExecution $completed,
        Throwable $failure,
    ): bool {
        $row = $this->rowFor($execution, $completed);
        $step = $this->steps->make($completed->step);

        if (! $step instanceof CompensatableStep) {
            // The class stopped declaring compensate() since the step
            // ran — a deploy between the failure and the pass. Nothing
            // can undo it, and guessing would be worse than stopping.
            $this->outcomes->stepFailure($execution, $row, CompensationFailedException::noLongerCompensatable($completed->step, $completed->position));

            return false;
        }

        // The class is a Step as well as a CompensatableStep — the
        // interface sits beside Step, not instead of it — so the undo
        // is charged to the very policy its step declared.
        $policy = $this->policies->forStep($step, $workflow);
        $startedAt = $this->clock->now();
        $timeoutAt = $policy->timeoutSeconds === null ? null : $startedAt->modify("+{$policy->timeoutSeconds} seconds");

        $row->markRunning($startedAt, $timeoutAt);
        $this->repository->saveStep($execution, $row);

        try {
            $step->compensate(new Compensation(
                context: $context,
                executionId: $execution->id,
                step: $completed->step,
                position: $completed->position,
                output: $completed->output,
                failure: $this->outcomes->errorMessage($failure),
                failureClass: $this->outcomes->errorClass($failure),
                attempt: $row->attempts,
            ));
        } catch (ClaimLostException $lost) {
            throw $lost;
        } catch (Throwable $exception) {
            // Charged to the compensated step's own policy: the step
            // that declared the undo declared how hard to try it. A
            // scheduled retry leaves the execution compensating and
            // due; an exhausted one stops the pass.
            $resolution = $this->outcomes->settle($execution, $row, $policy, FailurePolicy::Stop, $exception);

            return $resolution->terminalFailure === null && $resolution->retryScheduled === false;
        }

        $this->outcomes->completeStep($execution, $row, $context, null);

        return true;
    }

    /**
     * The compensation row for a completed step: the one already
     * appended for it, or a new one at the next free position.
     */
    private function rowFor(WorkflowExecution $execution, StepExecution $completed): StepExecution
    {
        foreach ($this->repository->steps($execution) as $row) {
            if ($row->compensatesStepExecutionId === $completed->id) {
                return $row;
            }
        }

        return $this->repository->appendCompensation($execution, $completed);
    }

    /**
     * The completed steps still to undo, newest first.
     *
     * Only steps that completed: a pending, skipped, or failed step did
     * nothing to undo, and the step that failed is in a state only the
     * application knows. Only steps whose class declares compensate().
     * Only those not already undone — a resumed pass picks up where the
     * last one stopped.
     *
     * @param  StepExecution[]  $steps
     * @return list<StepExecution>
     */
    private function pending(array $steps): array
    {
        $done = [];

        foreach ($steps as $row) {
            if ($row->compensatesStepExecutionId !== null && $row->isCompleted()) {
                $done[$row->compensatesStepExecutionId] = true;
            }
        }

        $pending = [];

        foreach ($steps as $row) {
            if ($row->isCompensation() || ! $row->isCompleted() || isset($done[$row->id])) {
                continue;
            }

            if (! is_a($row->step, CompensatableStep::class, true)) {
                continue;
            }

            $pending[] = $row;
        }

        // Reverse: the last thing done is the first thing undone.
        return array_reverse($pending);
    }
}
