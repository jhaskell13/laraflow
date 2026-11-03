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
    /**
     * Step ids that already have a compensation row, seen while
     * selecting what is pending.
     *
     * @var array<int, true>|null
     */
    private ?array $appended = null;

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
     * Undo what completed, in reverse.
     *
     * Complete when every undo ran; Scheduled when one is due again
     * later and the claim is already released; Stopped when one will
     * not be tried again, in which case the caller rests the execution
     * — the pass does not, because only the caller knows whether it is
     * the original claim (which throws) or a resumed one (which does
     * not).
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
    ): CompensationOutcome {
        $pending = $this->pending($steps);

        if ($pending === []) {
            return CompensationOutcome::Complete;
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
            $outcome = $this->undo($execution, $workflow, $context, $completed, $failure);

            if ($outcome !== CompensationOutcome::Complete) {
                $this->events->emit(new WorkflowCompensated(
                    ...$this->outcomes->identity($execution),
                    undone: $undone,
                    remaining: count($pending) - $undone,
                    complete: false,
                ));

                return $outcome;
            }

            $undone++;
        }

        $this->events->emit(new WorkflowCompensated(
            ...$this->outcomes->identity($execution),
            undone: $undone,
            remaining: 0,
            complete: true,
        ));

        return CompensationOutcome::Complete;
    }

    /**
     * Undo one step. The compensation's row is created first — so a
     * worker that dies inside compensate() leaves evidence that it
     * began, exactly as a step does — then run, then settled.
     *
     * Stopped when this compensation has failed for the last time:
     * the pass stops rather than reaching past it to undo earlier work
     * the failed one may have depended on.
     */
    private function undo(
        WorkflowExecution $execution,
        Workflow $workflow,
        WorkflowContext $context,
        StepExecution $completed,
        Throwable $failure,
    ): CompensationOutcome {
        $row = $this->rowFor($execution, $completed);
        $step = $this->steps->make($completed->step);

        if (! $step instanceof CompensatableStep) {
            // The class stopped declaring compensate() since the step
            // ran — a deploy between the failure and the pass. Nothing
            // can undo it, and the pass stops rather than reaching past
            // it: undoing earlier work while this one stands would
            // leave the saga in a state no code describes.
            $this->outcomes->stepFailure($execution, $row, CompensationFailedException::noLongerCompensatable($completed->step, $completed->position));

            return CompensationOutcome::Stopped;
        }

        // The class is a Step as well as a CompensatableStep — the
        // interface sits beside Step, not instead of it — so the undo
        // is charged to the very policy its step declared.
        $policy = $this->policies->forStep($step, $workflow);
        $startedAt = $this->clock->now();
        $timeoutAt = $policy->deadlineFrom($startedAt);

        $row->markRunning($startedAt, $timeoutAt);
        $this->repository->saveStep($execution, $row);

        // The context enters the *compensation's* row, not the step it
        // undoes: remember() is promised inside compensate() and needs
        // a position to scope its keys to, the deadline is this undo's,
        // and without this the whole pass would have shared the failed
        // forward step's idempotency scope — or, on a resumed pass,
        // had none at all and refused (Milestone 9 audit).
        $context->enterStep($row->position, $row->attempts, $timeoutAt, $row->step);

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

            return $resolution->retryScheduled
                ? CompensationOutcome::Scheduled
                : CompensationOutcome::Stopped;
        }

        $this->outcomes->completeStep($execution, $row, $context, null);

        return CompensationOutcome::Complete;
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
     * Whether this step's class was compensatable when it ran.
     *
     * Read from the row rather than the class: a deploy may have taken
     * the interface away, and that case must reach undo() to stop the
     * pass by name rather than be silently skipped. The evidence is
     * the compensation row itself — if one was ever appended, the step
     * was compensatable — and otherwise the class as it stands now.
     */
    private function wasCompensatable(StepExecution $row): bool
    {
        if ($this->appended !== null && isset($this->appended[$row->id])) {
            return true;
        }

        return is_a($row->step, CompensatableStep::class, true);
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
        $this->appended = [];

        foreach ($steps as $row) {
            if ($row->compensatesStepExecutionId === null) {
                continue;
            }

            // A compensation row exists for this step, so it was
            // compensatable when it ran, whatever its class says now.
            $this->appended[$row->compensatesStepExecutionId] = true;

            if ($row->isCompleted()) {
                $done[$row->compensatesStepExecutionId] = true;
            }
        }

        $pending = [];

        foreach ($steps as $row) {
            if ($row->isCompensation() || ! $row->isCompleted() || isset($done[$row->id])) {
                continue;
            }

            // A class that no longer declares compensate() is *not*
            // filtered out here: it must reach undo(), which stops the
            // pass by name. Skipping it would undo earlier work while
            // this one stood — the thing the stop rule exists to
            // prevent (Milestone 9 audit). A class that was never
            // compensatable has no row to undo and never had one.
            if (! class_exists($row->step) || ! $this->wasCompensatable($row)) {
                continue;
            }

            $pending[] = $row;
        }

        // Reverse: the last thing done is the first thing undone.
        return array_reverse($pending);
    }
}
