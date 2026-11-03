<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionSignalled;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowStepSkipped;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\InvalidSignalException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\PayloadTooLargeException;
use Laraflow\Exceptions\Workflow\RecordedFailure;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Exceptions\Workflow\StepPreviouslyFailedException;
use Laraflow\Exceptions\Workflow\StepTimeoutException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Support\DurableValue;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\ExecutionScope;
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

    protected Compensator $compensator;

    protected WaitResolver $waits;

    protected PlanMigrator $plans;

    /**
     * Collaborators are injected; the configured numbers and flags
     * arrive together in RunnerOptions. Construction is internal
     * wiring — resolve the runner from the container.
     */
    public function __construct(
        protected ExecutionRepository $repository,
        Container $container,
        protected LoggerInterface $logger,
        RunnerOptions $options,
        protected EventEmitter $events,
        ClockInterface $clock,
        PolicyResolver $policies,
        ErrorRedactor $redactor,
        protected ExecutionScope $scope,
        protected ChildDelivery $childDelivery,
        protected IdempotencyResolver $idempotency,
    ) {
        // Every collaborator is required: the service provider is the
        // one place that knows the configured clock, policy, redactor,
        // and delivery, and a runner silently built around defaults for
        // any of them was a runner that disagreed with the rest of the
        // application. Tests build one through TestCase::makeRunner().
        $this->clock = $clock;
        $this->options = $options;
        $this->parents = new ParentNotifier($repository, $logger, $childDelivery, $clock);
        $this->policies = $policies;
        $this->steps = new StepResolver($container);

        $this->outcomes = new OutcomeRecorder(
            repository: $repository,
            logger: $logger,
            events: $events,
            maxErrorLength: $this->options->maxErrorLength,
            includeErrorMessages: $this->options->includeErrorMessages,
            clock: $this->clock,
            redactor: $redactor,
            maxSuggestedRetryDelaySeconds: $this->options->maxSuggestedRetryDelaySeconds,
            parents: $this->parents,
        );

        $this->waits = new WaitResolver(
            repository: $repository,
            logger: $logger,
            clock: $clock,
            options: $options,
            childDelivery: $childDelivery,
            outcomes: $this->outcomes,
            steps: $this->steps,
        );

        $this->compensator = new Compensator(
            repository: $repository,
            steps: $this->steps,
            outcomes: $this->outcomes,
            policies: $policies,
            events: $events,
            clock: $clock,
        );

        $this->plans = new PlanMigrator(
            repository: $repository,
            steps: $this->steps,
            outcomes: $this->outcomes,
            logger: $logger,
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
            planVersion: $workflow->version(),
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
     * relying on "failure throws" should check isRetrying() and
     * isWaiting() on the returned handle — a step returning a Wait parks
     * the execution regardless of retry configuration — or keep retries
     * off and waits out of workflows they run in-process.
     *
     * @param  bool  $ignoreRetrySchedule  Claim a retrying execution even
     *                                     before its next_retry_at — the deliberate operator override
     *                                     behind laraflow:execute --now. Without it, an early claim
     *                                     throws RetryNotDueException.
     */
    public function execute(
        int $executionId,
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
        $this->scope->enter($execution);

        try {
            return $this->runClaimed($execution, $workflow, $ignoreRetrySchedule);
        } finally {
            $this->scope->leave();
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
     * @throws InvalidSignalException for an empty, over-long, or reserved name.
     * @throws SignalRefusedException when the execution is not waiting on this signal.
     * @throws UnserializableValueException if the payload is not JSON-serializable.
     * @throws PayloadTooLargeException if the payload exceeds execution.max_payload_bytes.
     */
    public function signal(int $executionId, string $signal, array $payload = []): WorkflowExecution
    {
        Wait::assertApplicationSignal($signal);

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
    public function cancel(int $executionId, ?string $reason = null): WorkflowExecution
    {
        $existing = $this->repository->find($executionId);

        if ($existing->isCancelled()) {
            // Idempotent: the original cancellation, and its event,
            // stand. Re-announcing a state that did not change would
            // make the event's once-per-execution guarantee a lie.
            return $existing;
        }

        $now = $this->now();

        $execution = $this->repository->requestCancellation(
            $executionId,
            $reason,
            $now,
            // A running execution whose claim is stale is unclaimed by
            // contract — recovery may even have abandoned it — and must
            // be cancellable outright, not left with a request no worker
            // will ever honour.
            $now->sub(new DateInterval("PT{$this->options->staleAfterSeconds}S")),
        );

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
    protected function cancelChildren(int $parentId, ?string $reason): void
    {
        $cursor = null;

        do {
            $page = $this->repository->list(new ExecutionFilter(
                status: [ExecutionStatus::Pending, ExecutionStatus::Running, ExecutionStatus::Retrying, ExecutionStatus::Waiting, ExecutionStatus::Compensating],
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

        $allRows = $this->repository->steps($execution);

        // A compensation pass already under way takes precedence over
        // the plan: the plan is finished with — it failed — and what
        // remains is undoing it. Detected from the rows rather than
        // the status, because a compensation whose own retry was
        // scheduled rests the execution as `retrying`, which the
        // existing due-retry scan delivers with no new mechanism.
        if ($this->compensator->isUnderWay($allRows)) {
            return $this->resumeCompensation($execution, $workflow, $allRows);
        }

        // The plan is the rows that compensate nothing: a compensation
        // is a step row appended after the plan, and the loop below —
        // like the definition check — is about the plan.
        $steps = array_values(array_filter($allRows, fn (StepExecution $row): bool => ! $row->isCompensation()));

        // Before the first step write, so a migration that throws is
        // bounded by the fruitless-claims counter like every other
        // fault in this position. The migrator proposes; the plan
        // check below judges — a migration whose output is not the
        // current definition fails as an ordinary mismatch.
        $steps = $this->plans->migrate($execution, $workflow, $steps);

        $this->steps->assertMatches($workflow, $execution, $steps);

        $context = new WorkflowContext(
            // array_replace, not array_merge: merge renumbers integer
            // keys and appends list-shaped input to the persisted
            // context on every claim.
            data: array_replace($execution->input, $execution->context),
            executionId: $execution->id,
            // Read from durable state, not from ambient request context:
            // a step re-run on a recovering worker must see the identifiers
            // the execution was created with.
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            idempotency: $this->idempotency,
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

            // Under Continue, a step that failed terminally in an earlier
            // claim of this execution spent its budget then. Resuming
            // after a later step's retry or wait must not run it again;
            // only an operator re-executing the *failed* execution does.
            // It still decides the outcome once everything else ran.
            if ($stepExecution->isFailed()
                && $failurePolicy === FailurePolicy::Continue
                && $execution->resumedFrom !== null
                && $execution->resumedFrom !== ExecutionStatus::Failed) {
                $firstFailure ??= StepPreviouslyFailedException::forStep(
                    $stepExecution->step,
                    $stepExecution->position,
                    $stepExecution->error,
                );

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
                return $this->failDefinition($execution, $workflow, $stepExecution, $context, $startedAt, $exception);
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
                    return $this->failDefinition($execution, $workflow, $stepExecution, $context, $startedAt, $exception);
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
                $resolution = $this->waits->resolve($execution, $stepExecution, $context, $policy, $failurePolicy, $waitsForcedDue, $startedAt);

                if ($resolution->resumed) {
                    continue;
                }

                if ($resolution->continueFailure !== null) {
                    // The step failed under FailurePolicy::Continue; the
                    // failure is already recorded on the step row.
                    $firstFailure ??= $resolution->continueFailure;

                    continue;
                }

                if ($resolution->terminalFailure !== null) {
                    return $this->failTerminally($execution, $workflow, $context, $resolution->terminalFailure);
                }

                // Parked again, retry scheduled, or failed terminally —
                // in every case this claim is finished with the
                // execution and the handle describes the resting state.
                // A re-park is a step boundary like the first park: a
                // cancellation requested across it is honoured here.
                if ($resolution->parked) {
                    $this->cancelIfRequested($execution);
                }

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

                $resolution = $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $timeout, StepAttemptStatus::TimedOut);

                if ($resolution->continueFailure !== null) {
                    $firstFailure ??= $resolution->continueFailure;

                    continue;
                }

                if ($resolution->terminalFailure !== null) {
                    return $this->failTerminally($execution, $workflow, $context, $resolution->terminalFailure);
                }

                return $execution;
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

            $timeoutAt = $policy->deadlineFrom($startedAt);

            $this->startStep($execution, $stepExecution, $context, $startedAt, $timeoutAt, $interrupted);

            // A step that writes to the context and then fails must
            // contribute nothing to what follows — in memory as well as
            // on disk, or Continue's next checkpoint would persist it.
            $contextBefore = $context->all();

            try {
                $output = $step->handle($context);

                if ($output instanceof Wait) {
                    // The park is a step boundary: a cancellation
                    // requested while handle() ran is honoured here, not
                    // deferred to a wait that nothing may ever resume.
                    if ($this->honourCancellationRequest($execution)) {
                        return $execution;
                    }

                    $this->waits->park($execution, $stepExecution, $context, $output);
                    $this->cancelIfRequested($execution);

                    return $execution;
                }

                // Checked before the checkpoint, so an oversized
                // payload fails the step by name instead of surfacing
                // as a driver error from inside the transaction.
                DurableValue::check($output, 'output', $this->options->maxPayloadBytes);
                DurableValue::check($context->all(), 'context', $this->options->maxPayloadBytes);

                $this->outcomes->completeStep($execution, $stepExecution, $context, $output);
            } catch (ClaimLostException $exception) {
                // Another worker owns the execution now; nothing we
                // persist would be trustworthy, including a failure.
                throw $exception;
            } catch (Throwable $exception) {
                $context->restore($contextBefore);

                // A scheduled retry returns the `retrying` handle without
                // throwing — a caller alerting on exceptions must not page
                // for an execution that will heal itself. Under Continue
                // the failure is recorded and the loop goes on, failing
                // the execution with the first one once everything ran.
                $resolution = $this->outcomes->settle($execution, $stepExecution, $policy, $failurePolicy, $exception);

                if ($resolution->continueFailure !== null) {
                    $firstFailure ??= $resolution->continueFailure;

                    continue;
                }

                if ($resolution->terminalFailure !== null) {
                    return $this->failTerminally($execution, $workflow, $context, $resolution->terminalFailure);
                }

                return $execution;
            }
        }

        if ($firstFailure !== null) {
            return $this->failTerminally($execution, $workflow, $context, $firstFailure);
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
     * Continue a compensation pass a previous claim did not finish.
     *
     * Nothing is thrown: the caller here is recovery or an operator
     * re-executing, not the code whose step failed, and the failure it
     * would be thrown is one already recorded. The handle says where
     * the pass got to.
     *
     * @param  StepExecution[]  $rows
     */
    protected function resumeCompensation(
        WorkflowExecution $execution,
        Workflow $workflow,
        array $rows,
    ): WorkflowExecution {
        $context = new WorkflowContext(
            data: array_replace($execution->input, $execution->context),
            executionId: $execution->id,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            idempotency: $this->idempotency,
        );

        // The exception object is gone — the pass may have spanned
        // claims — but its message and class were recorded with the
        // status, and the class must survive being rebuilt or a
        // resumed pass would rest the execution as a WorkflowException
        // rather than as what actually failed (Milestone 9 audit).
        $failure = new RecordedFailure(
            $execution->error ?? 'The execution failed.',
            $execution->errorClass ?? WorkflowException::class,
        );

        $outcome = $this->compensator->compensate($execution, $workflow, $context, $rows, $failure);

        if ($outcome === CompensationOutcome::Scheduled) {
            return $this->repository->find($execution->id);
        }

        // Complete or stopped, the execution rests: a pass that cannot
        // finish still ends, and the rows say how far it got.
        $this->outcomes->failRecorded($execution);

        return $execution;
    }

    /**
     * The execution has failed terminally: undo what completed, then
     * record the failure and throw it.
     *
     * The compensation pass runs under this claim, between the step's
     * failure (already durable) and the execution's (not yet), because
     * an execution that is about to be undone has not finished failing.
     * A pass that stops partway still ends the execution failed: the
     * compensation rows say what was undone and what was not.
     *
     * A compensation that scheduled a retry leaves the execution
     * `compensating` and due, so recovery delivers it and the pass
     * continues — nothing is thrown, and the handle describes it.
     */
    protected function failTerminally(
        WorkflowExecution $execution,
        Workflow $workflow,
        WorkflowContext $context,
        Throwable $failure,
    ): WorkflowExecution {
        $rows = $this->repository->steps($execution);

        if ($this->compensator->hasWork($rows)) {
            $outcome = $this->compensator->compensate($execution, $workflow, $context, $rows, $failure);

            if ($outcome === CompensationOutcome::Scheduled) {
                // An undo is due again later: scheduleRetry released
                // the claim and the due-retry scan will deliver it.
                return $this->repository->find($execution->id);
            }

            if ($outcome === CompensationOutcome::Stopped) {
                // An undo will not be tried again. The execution must
                // still come to rest on the failure that began the
                // pass — it was left claimed and `compensating`, which
                // nothing could claim and no operator could cancel
                // (Milestone 9 audit). What was undone and what was
                // not is on the compensation rows.
                $this->outcomes->failRecorded($execution);

                throw $failure;
            }
        }

        $this->outcomes->executionFailure($execution, $failure);

        throw $failure;
    }

    /**
     * A definition-class fault — a step class that cannot be built, a
     * policy or condition that throws — is persisted as the step's
     * terminal failure and rethrown; no policy is consulted. A step
     * found waiting is failed from where it is: it was never re-marked
     * running, and the failure transition is legal from Waiting.
     */
    protected function failDefinition(
        WorkflowExecution $execution,
        Workflow $workflow,
        StepExecution $stepExecution,
        WorkflowContext $context,
        DateTimeImmutable $startedAt,
        Throwable $exception,
    ): WorkflowExecution {
        if (! $stepExecution->isWaiting()) {
            $this->startStep($execution, $stepExecution, $context, $startedAt, null);
        }

        // The step's failure, then the same terminal path an ordinary
        // throw takes — including the compensation pass. A step class
        // that cannot be built is still a failure the steps before it
        // should be undone for; routing it past compensation would
        // have made the undo depend on *how* the workflow broke
        // (Milestone 9 audit).
        $this->outcomes->stepFailure($execution, $stepExecution, $exception);

        return $this->failTerminally($execution, $workflow, $context, $exception);
    }

    /**
     * After a park: a cancellation that landed between the boundary
     * check and the release would otherwise sit on a wait nothing may
     * ever claim. The execution is waiting now, so the immediate path
     * applies.
     */
    protected function cancelIfRequested(WorkflowExecution $execution): void
    {
        $fresh = $this->repository->find($execution->id);

        if ($fresh->cancelRequestedAt === null || ! $fresh->isWaiting()) {
            return;
        }

        $this->cancel($execution->id, $fresh->cancelReason);
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
        $context->enterStep($stepExecution->position, $stepExecution->attempts, $timeoutAt, $stepExecution->step);
        $this->scope->enterStep($stepExecution);

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
        $this->events->emit($event);
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
