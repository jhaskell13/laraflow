<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionSignalled;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepSkipped;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Events\WorkflowStepWaiting;
use Laraflow\Exceptions\Workflow\ChildWorkflowFailedException;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Exceptions\Workflow\StepTimeoutException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Exceptions\Workflow\WaitTimedOutException;
use Laraflow\Support\Clock;
use Laraflow\Support\DurableValue;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\LoggingContext;
use Laraflow\Support\PassthroughRedactor;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\PolicyResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs workflows against durable execution state.
 *
 * The lifecycle contract implemented here is documented in
 * docs/execution-lifecycle.md. In short: start() persists the execution
 * and its full step plan, execute() claims the execution and runs every
 * step that is not yet completed, checkpointing output and context after
 * each step, and run() is the synchronous combination of the two.
 */
class WorkflowRunner
{
    protected ClockInterface $clock;

    protected PolicyResolver $policies;

    protected RunnerOptions $options;

    protected StepResolver $steps;

    protected OutcomeRecorder $outcomes;

    protected ParentNotifier $parents;

    /**
     * Collaborators are injected; the configured numbers and flags
     * arrive together in RunnerOptions. Construction is internal
     * wiring — resolve the runner from the container.
     */
    public function __construct(
        protected ExecutionRepository $repository,
        Container $container,
        LoggerInterface $logger,
        ?RunnerOptions $options = null,
        protected ?EventEmitter $events = null,
        ?ClockInterface $clock = null,
        ?PolicyResolver $policies = null,
        ?ErrorRedactor $redactor = null,
        protected ?LoggingContext $logging = null,
        protected ?ChildDelivery $childDelivery = null,
    ) {
        $this->clock = $clock ?? new Clock;
        $this->options = $options ?? new RunnerOptions;
        $this->parents = new ParentNotifier($repository, $logger, $childDelivery, $this->clock);

        // The bare resolver's default policy disables retries, so a
        // runner constructed without one behaves exactly as it did
        // before retry policy existed.
        $this->policies = $policies ?? new PolicyResolver;
        $this->steps = new StepResolver($container);

        $this->outcomes = new OutcomeRecorder(
            repository: $repository,
            logger: $logger,
            events: $events,
            maxErrorLength: $this->options->maxErrorLength,
            includeErrorMessages: $this->options->includeErrorMessages,
            clock: $this->clock,
            redactor: $redactor ?? new PassthroughRedactor,
            maxSuggestedRetryDelaySeconds: $this->options->maxSuggestedRetryDelaySeconds,
            parents: $this->parents,
        );
    }

    /**
     * Create a pending execution and its complete step plan without
     * running anything.
     *
     * @param  array<string, mixed>  $input
     * @param  ExecutionOptions|null  $options  Correlation and tenant
     *                                          identifiers to record on the execution.
     *
     * @throws InvalidWorkflowDefinitionException if the workflow has no steps or a step is not a Step class.
     * @throws UnserializableValueException if the input is not JSON-serializable.
     */
    public function start(
        Workflow $workflow,
        array $input = [],
        ?ExecutionOptions $options = null
    ): WorkflowExecution {
        DurableValue::check($input, 'input', $this->options->maxPayloadBytes);

        return $this->repository->create(
            workflow: $workflow::class,
            input: $input,
            steps: $this->steps->plan($workflow),
            options: $options,
        );
    }

    /**
     * Claim an existing execution and run every step that has not
     * completed, in order. Used both for first execution and for
     * recovery after a failure or worker loss.
     *
     * Completed steps are skipped; their results reach later steps via
     * the restored context. Steps found in `running` state are treated
     * as interrupted and run again (the at-least-once boundary).
     *
     * On a step failure the step's policy decides: if a retry is
     * allowed, the failed attempt and its schedule are persisted and the
     * `retrying` handle is returned — nothing is thrown, because nothing
     * has terminally failed. When the policy refuses, or no policy
     * enables retries (the shipped default), the failure is persisted
     * and the step's exception rethrown unchanged. Synchronous callers
     * relying on "failure throws" should check isRetrying() on the
     * returned handle, or keep retries disabled for workflows they run
     * in-process.
     *
     * @param  bool  $ignoreRetrySchedule  Claim a retrying execution even
     *                                     before its next_retry_at — the deliberate operator override
     *                                     behind laraflow:execute --now. Without it, an early claim
     *                                     throws RetryNotDueException.
     */
    public function execute(
        int|string $executionId,
        Workflow $workflow,
        bool $ignoreRetrySchedule = false
    ): WorkflowExecution {
        $claimToken = bin2hex(random_bytes(16));

        $execution = $this->repository->claim(
            $executionId,
            $claimToken,
            $this->options->staleAfterSeconds,
            $ignoreRetrySchedule
        );

        // Published before anything runs and restored in the finally,
        // so every log record written while this execution is in
        // flight — including the application's own, from inside a step
        // — carries its identity, and none written afterwards do.
        $this->logging?->enter($execution);

        try {
            return $this->runClaimed($execution, $workflow, $ignoreRetrySchedule);
        } finally {
            $this->logging?->leave();
        }
    }

    /**
     * Durably record a signal for an execution waiting on it. The
     * payload becomes the waiting step's output, bounded by the same
     * payload limit as any other durable value, and the execution
     * becomes due immediately. Recording is not delivery: hand the id
     * to the dispatcher for the fast path, or let laraflow:recover
     * pick it up.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws SignalRefusedException when the execution is not waiting on this signal.
     * @throws UnserializableValueException if the payload is not JSON-serializable.
     */
    public function signal(int|string $executionId, string $signal, array $payload = []): WorkflowExecution
    {
        if (trim($signal) === '' || str_starts_with($signal, Wait::RESERVED_PREFIX)) {
            throw new InvalidArgumentException(
                'A signal name must be non-empty and must not start with the reserved ['.Wait::RESERVED_PREFIX.'] prefix.'
            );
        }

        DurableValue::check($payload, 'signal', $this->options->maxPayloadBytes);

        $execution = $this->repository->recordSignal($executionId, $signal, $payload, $this->now());

        $this->event(new WorkflowExecutionSignalled(
            ...$this->outcomes->identity($execution),
            signal: $signal,
        ));

        return $execution;
    }

    /**
     * Convenience: start() then execute() synchronously in this process.
     *
     * @param  array<string, mixed>  $input
     * @param  ExecutionOptions|null  $options  Correlation and tenant
     *                                          identifiers to record on the execution.
     */
    public function run(
        Workflow $workflow,
        array $input = [],
        ?ExecutionOptions $options = null
    ): WorkflowExecution {
        $execution = $this->start($workflow, $input, $options);

        return $this->execute($execution->id, $workflow);
    }

    /**
     * Cancel an execution, or request cancellation when a live worker
     * owns it.
     *
     * Returns the resulting handle: `isCancelled()` means it is done;
     * a still-`running` handle with `cancelRequestedAt` set means the
     * owning worker will stop at its next step boundary. A step already
     * in flight is never interrupted — bounding that is the job of the
     * step's own timeout and the queue worker's.
     *
     * @throws ExecutionNotFoundException
     * @throws ExecutionNotCancellableException when the execution has completed.
     */
    public function cancel(int|string $executionId, ?string $reason = null): WorkflowExecution
    {
        $existing = $this->repository->find($executionId);

        if ($existing->isCancelled()) {
            // Idempotent: the original cancellation, and its event,
            // stand. Re-announcing a state that did not change would
            // make the event's once-per-execution guarantee a lie.
            return $existing;
        }

        $execution = $this->repository->requestCancellation($executionId, $reason, $this->now());

        if ($execution->isCancelled() && $execution->cancelledAt !== null) {
            $this->event(new WorkflowExecutionCancelled(
                ...$this->outcomes->identity($execution),
                cancelledAt: $execution->cancelledAt,
                reason: $execution->cancelReason,
                durationMs: $execution->durationMs(),
            ));

            $this->parents->childFinished($execution);
        }

        // Cancellation cascades down, visibly: a parent's unfinished
        // children have no reason to keep working for it, and each
        // child's own cancellation fires its own event. Requested on a
        // still-running parent too — the children stop now, the parent
        // at its next step boundary. Bounded by page size per level and
        // by the depth bound overall.
        $this->cancelChildren($executionId, $reason);

        return $execution;
    }

    /**
     * Request cancellation of every unfinished child, recursively.
     */
    protected function cancelChildren(int|string $parentId, ?string $reason): void
    {
        $cursor = null;

        do {
            $page = $this->repository->list(new ExecutionFilter(
                status: [ExecutionStatus::Pending, ExecutionStatus::Running, ExecutionStatus::Retrying, ExecutionStatus::Waiting],
                parentId: $parentId,
            ), ExecutionRepository::MAX_PAGE_SIZE, $cursor);

            foreach ($page->executions as $child) {
                try {
                    $this->cancel($child->id, $reason);
                } catch (ExecutionNotCancellableException|ExecutionNotFoundException) {
                    // Finished or pruned between the scan and this call;
                    // the rest of the batch still deserves its cancel.
                }
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    /**
     * Run every non-completed step of an execution this worker has
     * already claimed, and bring the execution to its resting state.
     *
     * Split from execute() so the claim's logging context can be
     * restored in a finally without indenting the whole run.
     */
    protected function runClaimed(
        WorkflowExecution $execution,
        Workflow $workflow,
        bool $waitsForcedDue = false
    ): WorkflowExecution {
        $startedAt = $this->now();
        $execution->markRunning($startedAt);

        $this->event(new WorkflowExecutionStarted(
            ...$this->outcomes->identity($execution),
            startedAt: $execution->startedAt ?? $startedAt,
            // Measured against this claim, not the first start: a
            // recovered execution's wait is the wait since it was last
            // made available, which is the number that explains a slow
            // queue today.
            queueLagSeconds: $execution->queueLagSeconds($startedAt),
        ));

        $steps = $this->repository->steps($execution);

        $this->steps->assertMatches($workflow, $execution, $steps);

        $context = new WorkflowContext(
            data: array_merge($execution->input, $execution->context),
            executionId: $execution->id,
            // Read from durable state, not from ambient request context:
            // a step re-run on a recovering worker must see the identifiers
            // the execution was created with.
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
        );

        $failurePolicy = $workflow->failurePolicy();

        /** @var Throwable|null $firstFailure */
        $firstFailure = null;

        foreach ($steps as $stepExecution) {
            // A skipped step is a recorded decision, replayed exactly
            // like a completed checkpoint — never re-evaluated.
            if ($stepExecution->isCompleted() || $stepExecution->isSkipped()) {
                continue;
            }

            // Cancellation is cooperative: it takes effect between
            // steps, so whatever is in flight finishes and checkpoints
            // first. Laraflow cannot interrupt a running step.
            if ($this->honourCancellationRequest($execution)) {
                return $execution;
            }

            $startedAt = $this->now();

            // The step and its policy resolve before anything is marked
            // running, so the attempt's deadline is durable before
            // handle() executes — a worker that dies writes nothing, so
            // the deadline must already be on the row for a recovering
            // worker to judge the attempt against it. A missing or
            // invalid class is persisted as a step failure exactly as
            // before; definition problems never consult a policy.
            try {
                $step = $this->steps->make($stepExecution->step);
                $policy = $this->policies->forStep($step, $workflow);
            } catch (Throwable $exception) {
                $this->startStep($execution, $stepExecution, $context, $startedAt, null);
                $this->outcomes->failure($execution, $stepExecution, $exception);

                throw $exception;
            }

            // The condition is consulted at the start of every attempt
            // — pending, failed, or retrying. Not only pending: a failed
            // step may have failed in shouldRun() itself, which never
            // answered. A throwing condition is a definition-class
            // fault, handled exactly like a step class that cannot be
            // built. A recorded skip is never re-evaluated (the loop
            // skipped it above).
            if ($step instanceof ConditionalStep
                && ($stepExecution->isPending() || $stepExecution->isFailed() || $stepExecution->isRetrying())) {
                try {
                    $shouldRun = $step->shouldRun($context);
                } catch (Throwable $exception) {
                    $this->startStep($execution, $stepExecution, $context, $startedAt, null);
                    $this->outcomes->failure($execution, $stepExecution, $exception);

                    throw $exception;
                }

                if (! $shouldRun) {
                    // Under FailurePolicy::Continue a failed earlier step
                    // contributed nothing, so this context is not the
                    // final one and a false answer is not a final answer.
                    // The step stays pending and the condition gets its
                    // real vote when the failure is re-executed with the
                    // full context — the purity premise the durable skip
                    // rests on.
                    if ($firstFailure !== null) {
                        continue;
                    }

                    $stepExecution->markSkipped($this->now());
                    $this->repository->saveStep($execution, $stepExecution);

                    $this->event(new WorkflowStepSkipped(
                        ...$this->outcomes->identity($execution),
                        step: $stepExecution->step,
                        position: $stepExecution->position,
                    ));

                    continue;
                }
            }

            // A parked step: the wait resolved, timed out, or — on a
            // claim that healed a crash or forced an early resume — is
            // not due at all, in which case the execution parks again.
            if ($stepExecution->isWaiting()) {
                $resolution = $this->resolveWait($execution, $stepExecution, $context, $policy, $failurePolicy, $waitsForcedDue, $startedAt);

                if ($resolution->resumed) {
                    continue;
                }

                if ($resolution->continueFailure !== null) {
                    // The step failed under FailurePolicy::Continue; the
                    // failure is already recorded on the step row.
                    $firstFailure ??= $resolution->continueFailure;

                    continue;
                }

                // Parked again, retry scheduled, or failed terminally —
                // in every case this claim is finished with the
                // execution and the handle describes the resting state.
                return $execution;
            }

            // A step found `running` was interrupted: its worker died
            // between marking it running and checkpointing it. If its
            // deadline had also passed, the attempt is classified as
            // timed out and charged to the retry policy like any thrown
            // failure. Interrupted *before* the deadline (or with none)
            // stays a free re-run — worker loss is not the step's fault.
            if ($stepExecution->isRunning()
                && $stepExecution->timeoutAt !== null
                && $stepExecution->timeoutAt <= $startedAt) {
                $timeout = StepTimeoutException::forStep(
                    $stepExecution->step,
                    $stepExecution->position,
                    $stepExecution->timeoutAt,
                    $stepExecution->attempts,
                );

                if ($this->outcomes->scheduleRetry($execution, $stepExecution, $policy, $timeout, StepAttemptStatus::TimedOut)) {
                    return $execution;
                }

                if ($failurePolicy === FailurePolicy::Continue) {
                    $this->outcomes->stepFailure($execution, $stepExecution, $timeout, StepAttemptStatus::TimedOut);
                    $firstFailure ??= $timeout;

                    continue;
                }

                $this->outcomes->failure($execution, $stepExecution, $timeout, StepAttemptStatus::TimedOut);

                throw $timeout;
            }

            // An interrupted attempt that had not overrun is closed out
            // in history before the re-run opens the next one.
            $interrupted = $stepExecution->isRunning()
                ? new StepAttempt(
                    executionId: $execution->id,
                    stepExecutionId: $stepExecution->id,
                    attempt: $stepExecution->attempts,
                    status: StepAttemptStatus::Interrupted,
                    startedAt: $stepExecution->startedAt,
                )
                : null;

            $timeoutAt = $policy->timeoutSeconds !== null
                ? $startedAt->add(new DateInterval("PT{$policy->timeoutSeconds}S"))
                : null;

            $this->startStep($execution, $stepExecution, $context, $startedAt, $timeoutAt, $interrupted);

            try {
                $output = $step->handle($context);

                if ($output instanceof Wait) {
                    // The park is a step boundary: a cancellation
                    // requested while handle() ran is honoured here, not
                    // deferred to a wait that nothing may ever resume.
                    if ($this->honourCancellationRequest($execution)) {
                        return $execution;
                    }

                    $this->park($execution, $stepExecution, $context, $output);

                    return $execution;
                }

                // Checked before the checkpoint, so an oversized
                // payload fails the step by name instead of surfacing
                // as a driver error from inside the transaction.
                DurableValue::check($output, 'output', $this->options->maxPayloadBytes);
                DurableValue::check($context->all(), 'context', $this->options->maxPayloadBytes);

                $this->completeStep($execution, $stepExecution, $context, $output);
            } catch (ClaimLostException $exception) {
                // Another worker owns the execution now; nothing we
                // persist would be trustworthy, including a failure.
                throw $exception;
            } catch (Throwable $exception) {
                if ($this->outcomes->scheduleRetry($execution, $stepExecution, $policy, $exception)) {
                    // The retry is durably scheduled: step and execution
                    // are `retrying` with the claim released. Nothing has
                    // terminally failed, so nothing is thrown — a caller
                    // alerting on exceptions must not page for an
                    // execution that will heal itself.
                    return $execution;
                }

                if ($failurePolicy === FailurePolicy::Continue) {
                    // The workflow declared its steps independent: record
                    // this failure, keep going, and fail the execution —
                    // with this first exception — once everything ran.
                    $this->outcomes->stepFailure($execution, $stepExecution, $exception);
                    $firstFailure ??= $exception;

                    continue;
                }

                $this->outcomes->failure($execution, $stepExecution, $exception);

                throw $exception;
            }
        }

        if ($firstFailure !== null) {
            $this->outcomes->executionFailure($execution, $firstFailure);

            throw $firstFailure;
        }

        $completedAt = $this->now();
        $execution->markCompleted($completedAt);
        $this->repository->release($execution);

        $this->event(new WorkflowExecutionCompleted(
            ...$this->outcomes->identity($execution),
            completedAt: $completedAt,
            durationMs: $execution->durationMs(),
        ));

        $this->parents->childFinished($execution);

        return $execution;
    }

    /**
     * If cancellation was requested since this worker claimed the
     * execution, durably cancel it and release the claim. Reads fresh
     * state once per step boundary — the cost of noticing a request
     * made by another process.
     */
    protected function honourCancellationRequest(WorkflowExecution $execution): bool
    {
        $fresh = $this->repository->find($execution->id);

        if ($fresh->cancelRequestedAt === null) {
            return false;
        }

        $cancelledAt = $this->now();

        // Carry over the request metadata: this worker's handle predates
        // the request and knows nothing about it.
        $execution->cancelRequestedAt = $fresh->cancelRequestedAt;
        $execution->markCancelled($cancelledAt, $fresh->cancelReason);
        $this->repository->release($execution);

        $this->event(new WorkflowExecutionCancelled(
            ...$this->outcomes->identity($execution),
            cancelledAt: $cancelledAt,
            reason: $fresh->cancelReason,
            durationMs: $execution->durationMs(),
        ));

        $this->parents->childFinished($execution);

        // The cascade runs here too: a child created after cancel()'s
        // own cascade swept past — mid-step, before this boundary —
        // must not keep working for a cancelled parent.
        $this->cancelChildren($execution->id, $fresh->cancelReason);

        return true;
    }

    /**
     * Park the execution on the Wait a step returned: the step row
     * (waiting, with signal name, resume instant, or deadline) and the
     * context the step built before waiting commit in one claim-guarded
     * checkpoint; then the execution parks and the claim is released.
     * The step's open attempt stays open — it closes when the wait
     * resolves, so one handle() run is one attempt row.
     */
    protected function park(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        Wait $wait,
    ): void {
        DurableValue::check($context->all(), 'context', $this->options->maxPayloadBytes);

        $now = $this->now();
        $resumeAt = $wait->resumeInstant($now);
        $timeoutAt = $wait->timeoutInstant($now);

        $stepExecution->markWaiting($wait->signal, $resumeAt, $timeoutAt);
        $execution->context = $context->all();
        $this->repository->checkpoint($execution, $stepExecution);

        // A child wait heartbeats on stale_after rather than parking
        // indefinitely: when the claim comes due the parent consults the
        // child's record directly, which heals a signal lost to the
        // park race and a child whose dispatch never survived.
        $nextEventAt = $wait->signal !== null && str_starts_with($wait->signal, Wait::RESERVED_PREFIX.'child.')
            ? $now->add(new DateInterval("PT{$this->options->staleAfterSeconds}S"))
            : ($resumeAt ?? $timeoutAt);

        $execution->markWaiting($nextEventAt);
        $this->repository->release($execution);

        $this->event(new WorkflowStepWaiting(
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
    protected function resolveWait(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        Policy\StepPolicy $policy,
        FailurePolicy $failurePolicy,
        bool $forcedDue,
        DateTimeImmutable $now,
    ): WaitResolution {
        if ($stepExecution->waitSignal !== null && str_starts_with($stepExecution->waitSignal, Wait::RESERVED_PREFIX.'child.')) {
            return $this->resolveChildWait($execution, $stepExecution, $context, $policy, $failurePolicy, $now);
        }

        $signalled = $stepExecution->waitSignal !== null && $stepExecution->output !== null;
        $timedDue = $stepExecution->waitSignal === null
            && ($forcedDue || ($stepExecution->nextRetryAt !== null && $stepExecution->nextRetryAt <= $now));

        if ($signalled || $timedDue) {
            $payload = $stepExecution->output;

            if ($signalled) {
                $context->set((string) $stepExecution->waitSignal, $payload);
            }

            $this->completeStep($execution, $stepExecution, $context, $payload);

            return WaitResolution::resumed();
        }

        if ($stepExecution->waitSignal !== null
            && $stepExecution->timeoutAt !== null
            && $stepExecution->timeoutAt <= $now) {
            return $this->failWaitingStep($execution, $stepExecution, $policy, $failurePolicy, $this->waitTimeout($stepExecution), StepAttemptStatus::TimedOut);
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
    protected function resolveChildWait(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        Policy\StepPolicy $policy,
        FailurePolicy $failurePolicy,
        DateTimeImmutable $now,
    ): WaitResolution {
        // The payload round-trips through JSON, so its shape is checked
        // rather than assumed: a malformed one reads as a failed child.
        /** @var array<array-key, mixed>|null $outcome */
        $outcome = is_array($stepExecution->output) ? $stepExecution->output : null;

        if ($outcome === null) {
            $childId = substr((string) $stepExecution->waitSignal, strlen(Wait::RESERVED_PREFIX.'child.'));

            try {
                $child = $this->repository->find($childId);
            } catch (ExecutionNotFoundException) {
                $missing = ChildWorkflowFailedException::missing($stepExecution->step, $stepExecution->position, $childId);

                return $this->failWaitingStep($execution, $stepExecution, $policy, $failurePolicy, $missing);
            }

            $staleFailure = $child->status === ExecutionStatus::Failed
                && $child->finishedAt !== null
                && $stepExecution->startedAt !== null
                && $child->finishedAt < $stepExecution->startedAt;

            if (! $child->status->isFinished() || $staleFailure) {
                if ($child->status === ExecutionStatus::Pending || $staleFailure) {
                    try {
                        $this->childDelivery?->deliver($child->id);
                    } catch (Throwable $deliveryFailure) {
                        // The next heartbeat tries again; the child may
                        // also already be running on a worker.
                    }
                }

                return $this->parkAgain($execution, $now->add(new DateInterval("PT{$this->options->staleAfterSeconds}S")));
            }

            $outcome = ['execution_id' => $child->id, 'status' => $child->status->value];
        }

        if (($outcome['status'] ?? null) === ExecutionStatus::Completed->value) {
            $this->completeStep($execution, $stepExecution, $context, $outcome);

            return WaitResolution::resumed();
        }

        $childId = $outcome['execution_id'] ?? null;
        $status = $outcome['status'] ?? null;

        $failed = ChildWorkflowFailedException::forOutcome(
            $stepExecution->step,
            $stepExecution->position,
            is_int($childId) || is_string($childId) ? $childId : 'unknown',
            is_string($status) ? $status : 'unknown',
        );

        return $this->failWaitingStep($execution, $stepExecution, $policy, $failurePolicy, $failed);
    }

    /**
     * Complete a step — fresh from handle(), or by resolving its wait —
     * in one claim-guarded checkpoint: the step row, the context, and
     * the attempt row that closes this handle() run, whose duration
     * spans run and park alike. The event carries the same attempt
     * record that went to history, so the two can never disagree about
     * how long the step took.
     */
    protected function completeStep(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        mixed $output,
    ): void {
        $completedAt = $this->now();
        $stepExecution->markCompleted($output, $completedAt);
        $execution->context = $context->all();

        $attempt = new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $stepExecution->id,
            attempt: $stepExecution->attempts,
            status: StepAttemptStatus::Completed,
            startedAt: $stepExecution->startedAt,
            endedAt: $completedAt,
        );

        $this->repository->checkpoint($execution, $stepExecution, $attempt);

        $this->event(new WorkflowStepCompleted(
            ...$this->outcomes->identity($execution),
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
            completedAt: $completedAt,
            durationMs: $attempt->durationMs(),
        ));
    }

    /**
     * A waiting step's failure — a timed-out wait, or a child that
     * ended badly — charged under the step's policy exactly as a thrown
     * failure would be.
     */
    protected function failWaitingStep(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        Policy\StepPolicy $policy,
        FailurePolicy $failurePolicy,
        Throwable $exception,
        StepAttemptStatus $attemptStatus = StepAttemptStatus::Failed,
    ): WaitResolution {
        if ($this->outcomes->scheduleRetry($execution, $stepExecution, $policy, $exception, $attemptStatus)) {
            return WaitResolution::settled();
        }

        if ($failurePolicy === FailurePolicy::Continue) {
            $this->outcomes->stepFailure($execution, $stepExecution, $exception, $attemptStatus);

            return WaitResolution::continueWith($exception);
        }

        $this->outcomes->failure($execution, $stepExecution, $exception, $attemptStatus);

        throw $exception;
    }

    /**
     * Park the execution again and release the claim: the wait is not
     * due, or a child is still working.
     */
    protected function parkAgain(WorkflowExecution $execution, ?DateTimeImmutable $nextEventAt): WaitResolution
    {
        $execution->markWaiting($nextEventAt);
        $this->repository->release($execution);

        return WaitResolution::settled();
    }

    protected function waitTimeout(StepExecution $stepExecution): WaitTimedOutException
    {
        return WaitTimedOutException::forSignal(
            $stepExecution->step,
            $stepExecution->position,
            (string) $stepExecution->waitSignal,
            $stepExecution->timeoutAt ?? $this->now(),
            $stepExecution->attempts,
        );
    }

    /**
     * Durably mark a step running (with this attempt's deadline), close
     * out an interrupted predecessor in history when there is one,
     * announce the start, and point the context at the step.
     */
    protected function startStep(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $timeoutAt,
        ?StepAttempt $interrupted = null
    ): void {
        $stepExecution->markRunning($startedAt, $timeoutAt);
        $this->repository->saveStep($execution, $stepExecution, $interrupted);

        // Narrowed to this step *before* the start is announced. A
        // listener's own logging, and the optional lifecycle log,
        // both write during the event — with the keys set afterwards
        // they would be attributed to the previous step, or to none
        // at all on the first one.
        $context->enterStep($stepExecution->position, $stepExecution->attempts, $timeoutAt);
        $this->logging?->enterStep($stepExecution);

        $this->event(new WorkflowStepStarted(
            ...$this->outcomes->identity($execution),
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
        ));
    }

    /**
     * Fire a lifecycle event, if an emitter was provided. Emission is
     * observational and never affects execution state; see EventEmitter.
     */
    protected function event(object $event): void
    {
        $this->events?->emit($event);
    }

    /**
     * Every timestamp the runner writes comes from here. The default
     * clock reads Laravel's Date facade, so `Date::setTestNow()` still
     * controls it; tests that need an explicit schedule inject a fixed
     * PSR-20 clock instead.
     */
    protected function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
