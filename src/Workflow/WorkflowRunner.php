<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\ExecutionNotCancellableException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\StepTimeoutException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Support\Clock;
use Laraflow\Support\DurableValue;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\LoggingContext;
use Laraflow\Support\PassthroughRedactor;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\PolicyResolver;
use Laraflow\Workflow\Policy\StepPolicy;
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

    protected ErrorRedactor $redactor;

    protected StepResolver $steps;

    public function __construct(
        protected ExecutionRepository $repository,
        protected Container $container,
        protected LoggerInterface $logger,
        protected int $staleAfterSeconds = 300,
        protected int $maxErrorLength = 65535,
        protected ?EventEmitter $events = null,
        protected bool $includeErrorMessages = true,
        ?ClockInterface $clock = null,
        ?PolicyResolver $policies = null,
        ?ErrorRedactor $redactor = null,
        protected ?LoggingContext $logging = null,
        protected ?int $maxPayloadBytes = null,
    ) {
        $this->clock = $clock ?? new Clock;

        if ($this->maxPayloadBytes !== null && $this->maxPayloadBytes < 1) {
            throw new InvalidArgumentException(
                'laraflow.execution.max_payload_bytes must be at least 1 byte, or null for no limit. '
                .'A limit of zero would reject every payload, including an empty one.'
            );
        }

        // The bare resolver's default policy disables retries, so a
        // runner constructed without one behaves exactly as it did
        // before retry policy existed.
        $this->policies = $policies ?? new PolicyResolver;
        $this->redactor = $redactor ?? new PassthroughRedactor;
        $this->steps = new StepResolver($container);
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
        DurableValue::check($input, 'input', $this->maxPayloadBytes);

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
            $this->staleAfterSeconds,
            $ignoreRetrySchedule
        );

        // Published before anything runs and restored in the finally,
        // so every log record written while this execution is in
        // flight — including the application's own, from inside a step
        // — carries its identity, and none written afterwards do.
        $this->logging?->enter($execution);

        try {
            return $this->runClaimed($execution, $workflow);
        } finally {
            $this->logging?->leave();
        }
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
                executionId: $execution->id,
                workflow: $execution->workflow,
                correlationId: $execution->correlationId,
                tenantId: $execution->tenantId,
                cancelledAt: $execution->cancelledAt,
                reason: $execution->cancelReason,
                durationMs: $execution->durationMs(),
            ));
        }

        return $execution;
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
        Workflow $workflow
    ): WorkflowExecution {
        $startedAt = $this->now();
        $execution->markRunning($startedAt);

        $this->event(new WorkflowExecutionStarted(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
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
            if ($stepExecution->isCompleted()) {
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
                $this->persistFailure($execution, $stepExecution, $exception);

                throw $exception;
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

                if ($this->scheduleRetry($execution, $stepExecution, $policy, $timeout, StepAttemptStatus::TimedOut)) {
                    return $execution;
                }

                if ($failurePolicy === FailurePolicy::Continue) {
                    $this->persistStepFailure($execution, $stepExecution, $timeout, StepAttemptStatus::TimedOut);
                    $firstFailure ??= $timeout;

                    continue;
                }

                $this->persistFailure($execution, $stepExecution, $timeout, StepAttemptStatus::TimedOut);

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
                ? $startedAt->modify("+{$policy->timeoutSeconds} seconds")
                : null;

            $this->startStep($execution, $stepExecution, $context, $startedAt, $timeoutAt, $interrupted);

            try {
                $output = $step->handle($context);

                // Checked before the checkpoint, so an oversized
                // payload fails the step by name instead of surfacing
                // as a driver error from inside the transaction.
                DurableValue::check($output, 'output', $this->maxPayloadBytes);
                DurableValue::check($context->all(), 'context', $this->maxPayloadBytes);

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
                    executionId: $execution->id,
                    workflow: $execution->workflow,
                    correlationId: $execution->correlationId,
                    tenantId: $execution->tenantId,
                    step: $stepExecution->step,
                    position: $stepExecution->position,
                    attempt: $stepExecution->attempts,
                    completedAt: $completedAt,
                    // The same record that went to history, so the
                    // event and the stored attempt can never disagree
                    // about how long the step took.
                    durationMs: $attempt->durationMs(),
                ));
            } catch (ClaimLostException $exception) {
                // Another worker owns the execution now; nothing we
                // persist would be trustworthy, including a failure.
                throw $exception;
            } catch (Throwable $exception) {
                if ($this->scheduleRetry($execution, $stepExecution, $policy, $exception)) {
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
                    $this->persistStepFailure($execution, $stepExecution, $exception);
                    $firstFailure ??= $exception;

                    continue;
                }

                $this->persistFailure($execution, $stepExecution, $exception);

                throw $exception;
            }
        }

        if ($firstFailure !== null) {
            $this->failExecution($execution, $firstFailure);

            throw $firstFailure;
        }

        $completedAt = $this->now();
        $execution->markCompleted($completedAt);
        $this->repository->release($execution);

        $this->event(new WorkflowExecutionCompleted(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            completedAt: $completedAt,
            durationMs: $execution->durationMs(),
        ));

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
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            cancelledAt: $cancelledAt,
            reason: $fresh->cancelReason,
            durationMs: $execution->durationMs(),
        ));

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
        $this->repository->saveStep($stepExecution, $interrupted);

        // Narrowed to this step *before* the start is announced. A
        // listener's own logging, and the optional lifecycle log,
        // both write during the event — with the keys set afterwards
        // they would be attributed to the previous step, or to none
        // at all on the first one.
        $context->enterStep($stepExecution->position, $stepExecution->attempts, $timeoutAt);
        $this->logging?->enterStep($stepExecution);

        $this->event(new WorkflowStepStarted(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
        ));
    }

    /**
     * If the policy allows another attempt after this failure, persist
     * step and execution as `retrying` with next_retry_at, append the
     * failed attempt to history, and release the claim. Returns false
     * when the policy refuses, leaving the terminal path to the caller.
     *
     * If the retry state itself cannot be persisted, this logs the
     * storage failure and rethrows the *original* step exception — it
     * must not fall through to persistFailure(), because the step may
     * already be durably `retrying` and marking it failed from there
     * would replace a real diagnosis with a state-machine error. The
     * claim stays held, so stale-claim recovery picks the execution up,
     * the same contract persistFailure() has for its own storage
     * failures.
     */
    protected function scheduleRetry(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        StepPolicy $policy,
        Throwable $exception,
        StepAttemptStatus $attemptStatus = StepAttemptStatus::Failed
    ): bool {
        $attempt = $stepExecution->attempts;

        if (! $policy->shouldRetry($exception, $attempt)) {
            return false;
        }

        $error = $this->errorMessage($exception);
        $failedAt = $this->now();

        // Seeded by execution and position: one logical retry always
        // lands on the same instant, different executions spread apart.
        $delay = $policy->delaySeconds($attempt, "{$execution->id}:{$stepExecution->position}");
        $nextRetryAt = $failedAt->modify("+{$delay} seconds");

        $record = new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $stepExecution->id,
            attempt: $attempt,
            status: $attemptStatus,
            error: $error,
            errorClass: $exception::class,
            startedAt: $stepExecution->startedAt,
            endedAt: $failedAt,
            nextRetryAt: $nextRetryAt,
        );

        try {
            $stepExecution->markRetrying($error, $exception::class, $failedAt, $nextRetryAt);
            $this->repository->saveStep($stepExecution, $record);

            $execution->markRetrying($error, $exception::class, $nextRetryAt);
            $this->repository->release($execution);
        } catch (Throwable $storageFailure) {
            $this->logger->error('Laraflow could not persist a scheduled retry.', [
                'execution_id' => $execution->id,
                'step' => $stepExecution->step,
                'position' => $stepExecution->position,
                'original_exception' => $exception::class.': '.$this->errorMessage($exception),
                'storage_exception' => $storageFailure::class.': '.$storageFailure->getMessage(),
            ]);

            throw $exception;
        }

        $eventError = $this->includeErrorMessages ? $error : null;

        $this->event(new WorkflowStepFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $attempt,
            errorClass: $exception::class,
            error: $eventError,
            durationMs: $record->durationMs(),
        ));

        $this->event(new WorkflowStepRetryScheduled(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $attempt,
            maxAttempts: $policy->maxAttempts,
            errorClass: $exception::class,
            error: $eventError,
            nextRetryAt: $nextRetryAt,
            delaySeconds: $delay,
        ));

        return true;
    }

    /**
     * Persist one step's terminal failure (with its attempt history) and
     * announce it, leaving the execution running — the continue-on-failure
     * path. If the persistence itself fails, the storage failure is
     * logged and the step's exception thrown: with storage down there is
     * nothing trustworthy to continue with.
     */
    protected function persistStepFailure(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        Throwable $exception,
        StepAttemptStatus $attemptStatus = StepAttemptStatus::Failed
    ): void {
        $error = $this->errorMessage($exception);
        $failedAt = $this->now();

        $record = new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $stepExecution->id,
            attempt: $stepExecution->attempts,
            status: $attemptStatus,
            error: $error,
            errorClass: $exception::class,
            startedAt: $stepExecution->startedAt,
            endedAt: $failedAt,
        );

        try {
            $stepExecution->markFailed($error, $exception::class, $failedAt);
            $this->repository->saveStep($stepExecution, $record);
        } catch (Throwable $storageFailure) {
            $this->logger->error('Laraflow could not persist a workflow failure.', [
                'execution_id' => $execution->id,
                'step' => $stepExecution->step,
                'position' => $stepExecution->position,
                'original_exception' => $exception::class.': '.$this->errorMessage($exception),
                'storage_exception' => $storageFailure::class.': '.$storageFailure->getMessage(),
            ]);

            throw $exception;
        }

        $this->event(new WorkflowStepFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
            errorClass: $exception::class,
            error: $this->includeErrorMessages ? $error : null,
            durationMs: $record->durationMs(),
        ));
    }

    /**
     * Terminal execution failure at the end of a continue-on-failure
     * run: the first step failure is what the execution records and what
     * the caller is thrown. Storage-failure semantics match
     * persistFailure(): log, keep the claim, let recovery own it.
     */
    protected function failExecution(WorkflowExecution $execution, Throwable $exception): void
    {
        $error = $this->errorMessage($exception);
        $failedAt = $this->now();

        try {
            $execution->markFailed($error, $exception::class, $failedAt);
            $this->repository->release($execution);
        } catch (Throwable $storageFailure) {
            $this->logger->error('Laraflow could not persist a workflow failure.', [
                'execution_id' => $execution->id,
                'original_exception' => $exception::class.': '.$this->errorMessage($exception),
                'storage_exception' => $storageFailure::class.': '.$storageFailure->getMessage(),
            ]);

            return;
        }

        $this->event(new WorkflowExecutionFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            errorClass: $exception::class,
            error: $this->includeErrorMessages ? $error : null,
            failedAt: $failedAt,
            durationMs: $execution->durationMs(),
        ));
    }

    /**
     * Persist a step failure and the execution's failed state. If the
     * persistence itself fails, the original step exception still
     * propagates; the storage failure is logged instead of replacing it.
     */
    protected function persistFailure(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        Throwable $exception,
        StepAttemptStatus $attemptStatus = StepAttemptStatus::Failed
    ): void {
        $error = $this->errorMessage($exception);
        $failedAt = $this->now();

        $record = new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $stepExecution->id,
            attempt: $stepExecution->attempts,
            status: $attemptStatus,
            error: $error,
            errorClass: $exception::class,
            startedAt: $stepExecution->startedAt,
            endedAt: $failedAt,
        );

        try {
            $stepExecution->markFailed($error, $exception::class, $failedAt);
            $this->repository->saveStep($stepExecution, $record);

            $execution->markFailed($error, $exception::class, $failedAt);
            $this->repository->release($execution);
        } catch (Throwable $storageFailure) {
            $this->logger->error('Laraflow could not persist a workflow failure.', [
                'execution_id' => $execution->id,
                'step' => $stepExecution->step,
                'position' => $stepExecution->position,
                'original_exception' => $exception::class.': '.$this->errorMessage($exception),
                'storage_exception' => $storageFailure::class.': '.$storageFailure->getMessage(),
            ]);

            // Events describe durable state; none exists to describe.
            return;
        }

        $eventError = $this->includeErrorMessages ? $error : null;

        $this->event(new WorkflowStepFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
            errorClass: $exception::class,
            error: $eventError,
            durationMs: $record->durationMs(),
        ));

        $this->event(new WorkflowExecutionFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            correlationId: $execution->correlationId,
            tenantId: $execution->tenantId,
            errorClass: $exception::class,
            error: $eventError,
            failedAt: $failedAt,
            durationMs: $execution->durationMs(),
        ));
    }

    /**
     * The one place an exception becomes text Laraflow retains: redacted
     * first (laraflow.errors.redactor), then truncated. Every persisted
     * error column, attempt-history row, log record, and failure event
     * goes through here, so nothing the redactor strips can leak out a
     * side door. The thrown exception itself is untouched.
     */
    protected function errorMessage(Throwable $exception): string
    {
        return mb_substr(
            $this->redactor->redact($exception, $exception->getMessage()),
            0,
            $this->maxErrorLength,
        );
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
