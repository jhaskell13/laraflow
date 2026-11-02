<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateInterval;
use DateTimeImmutable;
use Laraflow\Events\WorkflowStepWaiting;
use Laraflow\Exceptions\Workflow\ChildWorkflowFailedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\FanOutFailedException;
use Laraflow\Exceptions\Workflow\WaitTimedOutException;
use Laraflow\Support\DurableValue;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * The wait state machine: parks an execution on the Wait a step
 * returned, and decides a claimed, parked step's fate when the
 * execution is next claimed — resumed by its signal or its time,
 * timed out, parked again because nothing is due, or, for a parent
 * parked on a child, settled from the child's outcome. The semantics
 * are fixed in docs/advanced-workflows.md; this class is where the
 * runner's step loop hands a waiting step and gets a StepResolution
 * back.
 *
 * It never cancels: a park is a step boundary, and the runner honours
 * a cancellation requested across it after this class returns, so the
 * cascade stays in one place.
 */
final class WaitResolver
{
    public function __construct(
        private ExecutionRepository $repository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private RunnerOptions $options,
        private ChildDelivery $childDelivery,
        private OutcomeRecorder $outcomes,
    ) {}

    /**
     * Park the execution on the Wait a step returned: the step row
     * (waiting, with signal name, resume instant, or deadline) and the
     * context the step built before waiting commit in one claim-guarded
     * checkpoint; then the execution parks and the claim is released.
     * The step's open attempt stays open — it closes when the wait
     * resolves, so one handle() run is one attempt row.
     */
    public function park(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        Wait $wait,
    ): void {
        DurableValue::check($context->all(), 'context', $this->options->maxPayloadBytes);

        $now = $this->clock->now();
        // Normalised to the clock's timezone: the columns hold naive
        // wall-clock values, so an instant a step supplied in another
        // zone would otherwise be read back shifted by the offset.
        $resumeAt = $wait->resumeInstant($now)?->setTimezone($now->getTimezone());
        $timeoutAt = $wait->timeoutInstant($now)?->setTimezone($now->getTimezone());

        $stepExecution->markWaiting($wait->signal, $resumeAt, $timeoutAt);
        $execution->context = $context->all();
        $this->repository->checkpoint($execution, $stepExecution);

        // A child wait — one child's, or a fan-out's join — heartbeats
        // on stale_after rather than parking indefinitely: when the
        // claim comes due the parent consults the record directly, which
        // heals a signal lost to the park race and a member whose
        // dispatch never survived.
        $nextEventAt = Wait::isMachinerySignal($wait->signal)
            ? $now->add(new DateInterval("PT{$this->options->staleAfterSeconds}S"))
            : ($resumeAt ?? $timeoutAt);

        $execution->markWaiting($nextEventAt);
        $this->repository->release($execution);

        $this->outcomes->emit(new WorkflowStepWaiting(
            ...$this->outcomes->identity($execution),
            step: $stepExecution->step,
            position: $stepExecution->position,
            signal: $wait->signal,
            resumeAt: $resumeAt,
            timeoutAt: $timeoutAt,
        ));
    }

    /**
     * Decide a claimed, parked step's fate.
     */
    public function resolve(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        StepPolicy $policy,
        FailurePolicy $failurePolicy,
        bool $forcedDue,
        DateTimeImmutable $now,
    ): StepResolution {
        if (Wait::isChildSignal($stepExecution->waitSignal)) {
            return $this->resolveChildWait($execution, $stepExecution, $context, $policy, $failurePolicy, $now);
        }

        if (Wait::isJoinSignal($stepExecution->waitSignal)) {
            return $this->resolveJoinWait($execution, $stepExecution, $context, $policy, $failurePolicy, $now);
        }

        $signalled = $stepExecution->waitSignal !== null && $stepExecution->output !== null;
        $timedDue = $stepExecution->waitSignal === null
            && ($forcedDue || ($stepExecution->nextRetryAt !== null && $stepExecution->nextRetryAt <= $now));

        if ($signalled || $timedDue) {
            $payload = $stepExecution->output;

            if ($signalled) {
                $context->set((string) $stepExecution->waitSignal, $payload);

                // The payload was bounded on its own at signal time; the
                // context it joins is bounded here, before the checkpoint,
                // so an overflow is the step's failure by name rather than
                // a driver error from inside the transaction.
                try {
                    DurableValue::check($context->all(), 'context', $this->options->maxPayloadBytes);
                } catch (Throwable $exception) {
                    $context->forget((string) $stepExecution->waitSignal);

                    return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $exception);
                }
            }

            $this->outcomes->completeStep($execution, $stepExecution, $context, $payload);

            return StepResolution::resumed();
        }

        if ($stepExecution->waitSignal !== null
            && $stepExecution->timeoutAt !== null
            && $stepExecution->timeoutAt <= $now) {
            return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $this->waitTimeout($stepExecution), StepAttemptStatus::TimedOut);
        }

        // Not due: a crash between checkpoint and release left the
        // execution claimed, or an operator claimed a signal wait early
        // — there is nothing to resume it with, so it parks again. A
        // forced claim resumes a *timed* wait early; a signal wait
        // without its signal has no output to complete with.
        return $this->parkAgain($execution, $stepExecution->waitSignal === null
            ? $stepExecution->nextRetryAt
            : $stepExecution->timeoutAt);
    }

    /**
     * A parent parked on its child. The signal payload is the child's
     * resting outcome; without one — the heartbeat came due, or the
     * child's signal was refused in the park race — the child's record
     * is consulted directly. A pending child lost its dispatch and is
     * re-delivered; running, retrying, and waiting children are left to
     * their own machinery, recovery's bounds included — re-delivering a
     * stale child here would bypass the abandonment bound. A child
     * whose failure predates this parent attempt was already charged
     * for it, so it is resumed rather than charged twice. A missing
     * child was pruned; nothing can finish it.
     */
    private function resolveChildWait(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        StepPolicy $policy,
        FailurePolicy $failurePolicy,
        DateTimeImmutable $now,
    ): StepResolution {
        // The payload round-trips through JSON, so its shape is checked
        // rather than assumed: a malformed one reads as a failed child.
        /** @var array<array-key, mixed>|null $outcome */
        $outcome = is_array($stepExecution->output) ? $stepExecution->output : null;

        if ($outcome === null) {
            $childId = Wait::childIdFrom((string) $stepExecution->waitSignal);

            // Only forChild() writes a child signal, and it writes digits.
            // Anything else on the row is damage, and the failure should
            // say so rather than report a child that was never named.
            if ($childId === 0) {
                $malformed = ChildWorkflowFailedException::malformedSignal($stepExecution->step, $stepExecution->position, (string) $stepExecution->waitSignal);

                return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $malformed);
            }

            try {
                $child = $this->repository->find($childId);
            } catch (ExecutionNotFoundException) {
                $missing = ChildWorkflowFailedException::missing($stepExecution->step, $stepExecution->position, $childId);

                return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $missing);
            }

            // A child whose workflow class is gone cannot be run by
            // anyone; re-delivering it every heartbeat would only fill
            // failed_jobs. The same bound recovery applies, applied here
            // because the parent — not recovery — schedules this child.
            if (! class_exists($child->workflow) || ! is_subclass_of($child->workflow, Workflow::class)) {
                $gone = ChildWorkflowFailedException::workflowMissing($stepExecution->step, $stepExecution->position, $child->id, $child->workflow);

                return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $gone);
            }

            $staleFailure = $child->status === ExecutionStatus::Failed
                && $child->finishedAt !== null
                && $stepExecution->startedAt !== null
                && $child->finishedAt < $stepExecution->startedAt;

            if (! $child->status->isFinished() || $staleFailure) {
                if ($child->status === ExecutionStatus::Pending || $staleFailure) {
                    try {
                        $this->childDelivery->deliver($child->id);
                    } catch (Throwable $deliveryFailure) {
                        // The next heartbeat tries again; the child may
                        // also already be running on a worker. Said out
                        // loud, or a dead queue becomes an invisible loop.
                        $this->logger->warning('Laraflow could not re-deliver a child execution from its parent heartbeat.', [
                            'parent_execution_id' => $execution->id,
                            'child_execution_id' => $child->id,
                            'exception' => $deliveryFailure::class.': '.$deliveryFailure->getMessage(),
                        ]);
                    }
                }

                return $this->parkAgain($execution, $now->add(new DateInterval("PT{$this->options->staleAfterSeconds}S")));
            }

            $outcome = ['execution_id' => $child->id, 'status' => $child->status->value];
        }

        if (($outcome['status'] ?? null) === ExecutionStatus::Completed->value) {
            $this->outcomes->completeStep($execution, $stepExecution, $context, $outcome);

            return StepResolution::resumed();
        }

        // The payload names the child; the wait signal is the durable
        // link and answers for a payload that does not.
        $childId = $outcome['execution_id'] ?? null;
        $status = $outcome['status'] ?? null;

        $failed = ChildWorkflowFailedException::forOutcome(
            $stepExecution->step,
            $stepExecution->position,
            is_int($childId) ? $childId : Wait::childIdFrom((string) $stepExecution->waitSignal),
            is_string($status) ? $status : 'unknown',
        );

        return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $failed);
    }

    /**
     * A fan-out parked on its join. The record is consulted directly
     * every time — one indexed aggregate — rather than the signal's
     * payload trusted: the last member to finish signals the join, but a
     * member resumed by a retry still reads `failed` until it rests
     * again, and the heartbeat may come due before any signal does. A
     * group still going has its lost dispatches and resumable failures
     * handed back to delivery and parks again; a group that is done is
     * the step's outcome, happy or not.
     */
    private function resolveJoinWait(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        StepPolicy $policy,
        FailurePolicy $failurePolicy,
        DateTimeImmutable $now,
    ): StepResolution {
        $signal = (string) $stepExecution->waitSignal;

        // Only forJoin() writes a join signal, and it writes this row's
        // own id. Anything else is damage, and the failure should say so.
        if (Wait::joinStepIdFrom($signal) !== $stepExecution->id) {
            return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, FanOutFailedException::malformedSignal($stepExecution->step, $stepExecution->position, $signal));
        }

        $group = new FanOutGroup($this->repository, $execution, $stepExecution);
        $progress = $group->progress();

        if ($progress->total() === 0) {
            // Members were declared and created, or the step would not
            // be parked; none remaining means retention took them, or
            // the rows were altered. Nothing can finish an empty group.
            return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, FanOutFailedException::noMembers($stepExecution->step, $stepExecution->position, $signal));
        }

        try {
            $outcome = $group->outcome($progress);
        } catch (FanOutFailedException $failed) {
            return $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $failed);
        }

        if ($outcome === null) {
            $group->redeliver($this->childDelivery, $this->logger);

            return $this->parkAgain($execution, $now->add(new DateInterval("PT{$this->options->staleAfterSeconds}S")));
        }

        $this->outcomes->completeStep($execution, $stepExecution, $context, $outcome);

        return StepResolution::resumed();
    }

    /**
     * Park the execution again and release the claim: the wait is not
     * due, or a child is still working. The runner honours a pending
     * cancellation once this returns.
     */
    private function parkAgain(WorkflowExecution $execution, ?DateTimeImmutable $nextEventAt): StepResolution
    {
        $execution->markWaiting($nextEventAt);
        $this->repository->release($execution);

        return StepResolution::parked();
    }

    private function waitTimeout(StepExecution $stepExecution): WaitTimedOutException
    {
        return WaitTimedOutException::forSignal(
            $stepExecution->step,
            $stepExecution->position,
            (string) $stepExecution->waitSignal,
            $stepExecution->timeoutAt ?? $this->clock->now(),
            $stepExecution->attempts,
        );
    }
}
