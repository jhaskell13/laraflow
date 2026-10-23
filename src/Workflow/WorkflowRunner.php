<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\StepTimeoutException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Support\Clock;
use Laraflow\Support\DurableValue;
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
    ) {
        $this->clock = $clock ?? new Clock;

        // The bare resolver's default policy disables retries, so a
        // runner constructed without one behaves exactly as it did
        // before retry policy existed.
        $this->policies = $policies ?? new PolicyResolver;
    }

    /**
     * Create a pending execution and its complete step plan without
     * running anything.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidWorkflowDefinitionException if the workflow has no steps or a step is not a Step class.
     * @throws UnserializableValueException if the input is not JSON-serializable.
     */
    public function start(Workflow $workflow, array $input = []): WorkflowExecution
    {
        DurableValue::assert($input, 'input');

        return $this->repository->create(
            workflow: $workflow::class,
            input: $input,
            steps: $this->validatedPlan($workflow),
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

        $startedAt = $this->now();
        $execution->markRunning($startedAt);

        $this->event(new WorkflowExecutionStarted(
            executionId: $execution->id,
            workflow: $execution->workflow,
            startedAt: $execution->startedAt ?? $startedAt,
        ));

        $steps = $this->repository->steps($execution);

        $this->assertDefinitionMatches($workflow, $execution, $steps);

        $context = new WorkflowContext(
            data: array_merge($execution->input, $execution->context),
            executionId: $execution->id,
        );

        foreach ($steps as $stepExecution) {
            if ($stepExecution->isCompleted()) {
                continue;
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
                $step = $this->makeStep($stepExecution->step);
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

                DurableValue::assert($output, 'output');
                DurableValue::assert($context->all(), 'context');

                $completedAt = $this->now();
                $stepExecution->markCompleted($output, $completedAt);
                $execution->context = $context->all();

                $this->repository->checkpoint($execution, $stepExecution, new StepAttempt(
                    executionId: $execution->id,
                    stepExecutionId: $stepExecution->id,
                    attempt: $stepExecution->attempts,
                    status: StepAttemptStatus::Completed,
                    startedAt: $stepExecution->startedAt,
                    endedAt: $completedAt,
                ));

                $this->event(new WorkflowStepCompleted(
                    executionId: $execution->id,
                    workflow: $execution->workflow,
                    step: $stepExecution->step,
                    position: $stepExecution->position,
                    attempt: $stepExecution->attempts,
                    completedAt: $completedAt,
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

                $this->persistFailure($execution, $stepExecution, $exception);

                throw $exception;
            }
        }

        $completedAt = $this->now();
        $execution->markCompleted($completedAt);
        $this->repository->release($execution);

        $this->event(new WorkflowExecutionCompleted(
            executionId: $execution->id,
            workflow: $execution->workflow,
            completedAt: $completedAt,
        ));

        return $execution;
    }

    /**
     * Convenience: start() then execute() synchronously in this process.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(Workflow $workflow, array $input = []): WorkflowExecution
    {
        $execution = $this->start($workflow, $input);

        return $this->execute($execution->id, $workflow);
    }

    /**
     * The supplied workflow must be the one the execution was created
     * from: same class, same steps, same order. Otherwise durable state
     * would be silently reinterpreted by different code.
     *
     * @param  StepExecution[]  $stepExecutions
     */
    protected function assertDefinitionMatches(
        Workflow $workflow,
        WorkflowExecution $execution,
        array $stepExecutions
    ): void {
        if ($execution->workflow !== $workflow::class) {
            throw new WorkflowDefinitionMismatchException(
                "Execution [{$execution->id}] was created from [{$execution->workflow}], "
                .'not ['.$workflow::class.'].'
            );
        }

        $definedSteps = $workflow->steps();
        $persistedSteps = array_map(
            fn (StepExecution $step): string => $step->step,
            $stepExecutions
        );

        if ($definedSteps !== $persistedSteps) {
            throw new WorkflowDefinitionMismatchException(
                "The step plan persisted for execution [{$execution->id}] does not match "
                .'the current definition of ['.$workflow::class.']. '
                .'Persisted: ['.implode(', ', $persistedSteps).']. '
                .'Defined: ['.implode(', ', $definedSteps).']. '
                .'The workflow code has changed since this execution was created.'
            );
        }
    }

    /**
     * Validate the user-supplied step list before anything is persisted.
     * The declared return type of Workflow::steps() is a contract for
     * authors, not a guarantee, so the value is treated as untrusted here.
     *
     * @return list<class-string<Step>>
     */
    protected function validatedPlan(Workflow $workflow): array
    {
        return $this->validateSteps($workflow::class, $workflow->steps());
    }

    /**
     * @param  array<mixed>  $steps
     * @return list<class-string<Step>>
     */
    protected function validateSteps(string $workflow, array $steps): array
    {
        if ($steps === []) {
            throw InvalidWorkflowDefinitionException::noSteps($workflow);
        }

        $plan = [];

        foreach ($steps as $position => $step) {
            if (! is_string($step) || ! class_exists($step) || ! is_subclass_of($step, Step::class)) {
                throw InvalidWorkflowDefinitionException::invalidStep($workflow, $position, $step);
            }

            $plan[] = $step;
        }

        return $plan;
    }

    protected function makeStep(string $stepClass): Step
    {
        if (! class_exists($stepClass)) {
            throw new WorkflowDefinitionMismatchException(
                "Step class [{$stepClass}] no longer exists."
            );
        }

        $step = $this->container->make($stepClass);

        if (! $step instanceof Step) {
            throw new WorkflowDefinitionMismatchException(
                "Step class [{$stepClass}] does not implement ".Step::class.'.'
            );
        }

        return $step;
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

        $this->event(new WorkflowStepStarted(
            executionId: $execution->id,
            workflow: $execution->workflow,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
        ));

        $context->enterStep($stepExecution->position, $stepExecution->attempts, $timeoutAt);
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

        $error = mb_substr($exception->getMessage(), 0, $this->maxErrorLength);
        $failedAt = $this->now();

        // Seeded by execution and position: one logical retry always
        // lands on the same instant, different executions spread apart.
        $delay = $policy->delaySeconds($attempt, "{$execution->id}:{$stepExecution->position}");
        $nextRetryAt = $failedAt->modify("+{$delay} seconds");

        try {
            $stepExecution->markRetrying($error, $exception::class, $failedAt, $nextRetryAt);
            $this->repository->saveStep($stepExecution, new StepAttempt(
                executionId: $execution->id,
                stepExecutionId: $stepExecution->id,
                attempt: $attempt,
                status: $attemptStatus,
                error: $error,
                errorClass: $exception::class,
                startedAt: $stepExecution->startedAt,
                endedAt: $failedAt,
                nextRetryAt: $nextRetryAt,
            ));

            $execution->markRetrying($error, $exception::class, $nextRetryAt);
            $this->repository->release($execution);
        } catch (Throwable $storageFailure) {
            $this->logger->error('Laraflow could not persist a scheduled retry.', [
                'execution_id' => $execution->id,
                'step' => $stepExecution->step,
                'position' => $stepExecution->position,
                'original_exception' => $exception::class.': '.$exception->getMessage(),
                'storage_exception' => $storageFailure::class.': '.$storageFailure->getMessage(),
            ]);

            throw $exception;
        }

        $eventError = $this->includeErrorMessages ? $error : null;

        $this->event(new WorkflowStepFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $attempt,
            errorClass: $exception::class,
            error: $eventError,
        ));

        $this->event(new WorkflowStepRetryScheduled(
            executionId: $execution->id,
            workflow: $execution->workflow,
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
        $error = mb_substr($exception->getMessage(), 0, $this->maxErrorLength);
        $failedAt = $this->now();

        try {
            $stepExecution->markFailed($error, $exception::class, $failedAt);
            $this->repository->saveStep($stepExecution, new StepAttempt(
                executionId: $execution->id,
                stepExecutionId: $stepExecution->id,
                attempt: $stepExecution->attempts,
                status: $attemptStatus,
                error: $error,
                errorClass: $exception::class,
                startedAt: $stepExecution->startedAt,
                endedAt: $failedAt,
            ));

            $execution->markFailed($error, $exception::class, $failedAt);
            $this->repository->release($execution);
        } catch (Throwable $storageFailure) {
            $this->logger->error('Laraflow could not persist a workflow failure.', [
                'execution_id' => $execution->id,
                'step' => $stepExecution->step,
                'position' => $stepExecution->position,
                'original_exception' => $exception::class.': '.$exception->getMessage(),
                'storage_exception' => $storageFailure::class.': '.$storageFailure->getMessage(),
            ]);

            // Events describe durable state; none exists to describe.
            return;
        }

        $eventError = $this->includeErrorMessages ? $error : null;

        $this->event(new WorkflowStepFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
            errorClass: $exception::class,
            error: $eventError,
        ));

        $this->event(new WorkflowExecutionFailed(
            executionId: $execution->id,
            workflow: $execution->workflow,
            errorClass: $exception::class,
            error: $eventError,
            failedAt: $failedAt,
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
