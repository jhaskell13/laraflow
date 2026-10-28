<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Exceptions\SuggestsRetryDelay;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Support\Clock;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\PassthroughRedactor;
use Laraflow\Workflow\Policy\StepPolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Writes down how a step or an execution ended, and announces it.
 *
 * Four outcomes, and their storage-failure behaviour differs on
 * purpose — the differences are the contract, not an inconsistency:
 *
 * | Outcome          | If the write itself fails                        |
 * |------------------|--------------------------------------------------|
 * | `scheduleRetry`  | Rethrow the step's exception. The step may already be durably `retrying`, and marking it failed from there would replace a real diagnosis with a state-machine error. |
 * | `stepFailure`    | Rethrow the step's exception: with storage down there is nothing trustworthy to continue a `Continue`-policy run with. |
 * | `failure`        | Return, so the step's original exception keeps propagating from the runner rather than being masked by a secondary one. |
 * | `executionFailure` | Return, same reason. |
 *
 * In every case the claim is kept and the storage error is logged, so
 * stale-claim recovery owns the execution rather than it being left in
 * a state nothing will revisit.
 *
 * A ClaimLostException is not a storage failure and is never treated
 * as one: another worker owns the execution now, so there is no claim
 * to keep and nothing of this worker's to log as unpersisted. It
 * propagates unchanged, and the step's own exception is dropped with
 * it — the new owner will run the step again and record its own outcome.
 */
class OutcomeRecorder
{
    protected ClockInterface $clock;

    protected ErrorRedactor $redactor;

    public function __construct(
        protected ExecutionRepository $repository,
        protected LoggerInterface $logger,
        protected ?EventEmitter $events = null,
        protected int $maxErrorLength = 65535,
        protected bool $includeErrorMessages = true,
        ?ClockInterface $clock = null,
        ?ErrorRedactor $redactor = null,
        protected int $maxSuggestedRetryDelaySeconds = 3600,
    ) {
        $this->clock = $clock ?? new Clock;
        $this->redactor = $redactor ?? new PassthroughRedactor;
    }

    /**
     * If the policy allows another attempt after this failure, persist
     * step and execution as `retrying` with next_retry_at, append the
     * failed attempt to history, and release the claim. Returns false
     * when the policy refuses, leaving the terminal path to the caller.
     */
    public function scheduleRetry(
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
        $failedAt = $this->clock->now();

        // Seeded by execution and position: one logical retry always
        // lands on the same instant, different executions spread apart.
        $delay = $policy->delaySeconds($attempt, "{$execution->id}:{$stepExecution->position}");

        // A provider that said when to come back is believed, up to the
        // configured cap, and never undercut by our own backoff: the
        // policy's delay protects the dependency, the suggestion is the
        // dependency protecting itself.
        if ($exception instanceof SuggestsRetryDelay) {
            $suggested = $exception->suggestedRetryDelaySeconds($failedAt);

            if ($suggested !== null) {
                $delay = max($delay, min($suggested, $this->maxSuggestedRetryDelaySeconds));
            }
        }

        $nextRetryAt = $failedAt->modify("+{$delay} seconds");

        $record = $this->attempt($execution, $stepExecution, $attemptStatus, $error, $exception, $failedAt, $nextRetryAt);

        try {
            $stepExecution->markRetrying($error, $exception::class, $failedAt, $nextRetryAt);
            $this->repository->saveStep($execution, $stepExecution, $record);

            $execution->markRetrying($error, $exception::class, $nextRetryAt);
            $this->repository->release($execution);
        } catch (ClaimLostException $lost) {
            throw $lost;
        } catch (Throwable $storageFailure) {
            $this->logStorageFailure('a scheduled retry', $execution, $stepExecution, $exception, $storageFailure);

            throw $exception;
        }

        $this->announceStepFailure($execution, $stepExecution, $attempt, $exception, $error, $record);

        $this->emit(new WorkflowStepRetryScheduled(
            ...$this->identity($execution),
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $attempt,
            maxAttempts: $policy->maxAttempts,
            errorClass: $exception::class,
            error: $this->eventError($error),
            nextRetryAt: $nextRetryAt,
            delaySeconds: $delay,
        ));

        return true;
    }

    /**
     * Persist one step's terminal failure and announce it, leaving the
     * execution running — the continue-on-failure path.
     */
    public function stepFailure(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        Throwable $exception,
        StepAttemptStatus $attemptStatus = StepAttemptStatus::Failed
    ): void {
        $error = $this->errorMessage($exception);
        $failedAt = $this->clock->now();
        $record = $this->attempt($execution, $stepExecution, $attemptStatus, $error, $exception, $failedAt);

        try {
            $stepExecution->markFailed($error, $exception::class, $failedAt);
            $this->repository->saveStep($execution, $stepExecution, $record);
        } catch (ClaimLostException $lost) {
            throw $lost;
        } catch (Throwable $storageFailure) {
            $this->logStorageFailure('a workflow failure', $execution, $stepExecution, $exception, $storageFailure);

            throw $exception;
        }

        $this->announceStepFailure($execution, $stepExecution, $stepExecution->attempts, $exception, $error, $record);
    }

    /**
     * Persist a step failure and the execution's failed state. If the
     * persistence itself fails, the original step exception still
     * propagates; the storage failure is logged instead of replacing it.
     */
    public function failure(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        Throwable $exception,
        StepAttemptStatus $attemptStatus = StepAttemptStatus::Failed
    ): void {
        $error = $this->errorMessage($exception);
        $failedAt = $this->clock->now();
        $record = $this->attempt($execution, $stepExecution, $attemptStatus, $error, $exception, $failedAt);

        try {
            $stepExecution->markFailed($error, $exception::class, $failedAt);
            $this->repository->saveStep($execution, $stepExecution, $record);

            $execution->markFailed($error, $exception::class, $failedAt);
            $this->repository->release($execution);
        } catch (ClaimLostException $lost) {
            throw $lost;
        } catch (Throwable $storageFailure) {
            $this->logStorageFailure('a workflow failure', $execution, $stepExecution, $exception, $storageFailure);

            // Events describe durable state; none exists to describe.
            return;
        }

        $this->announceStepFailure($execution, $stepExecution, $stepExecution->attempts, $exception, $error, $record);
        $this->announceExecutionFailure($execution, $exception, $error, $failedAt);
    }

    /**
     * Terminal execution failure at the end of a continue-on-failure
     * run: the first step failure is what the execution records and
     * what the caller is thrown.
     */
    public function executionFailure(WorkflowExecution $execution, Throwable $exception): void
    {
        $error = $this->errorMessage($exception);
        $failedAt = $this->clock->now();

        try {
            $execution->markFailed($error, $exception::class, $failedAt);
            $this->repository->release($execution);
        } catch (ClaimLostException $lost) {
            throw $lost;
        } catch (Throwable $storageFailure) {
            $this->logStorageFailure('a workflow failure', $execution, null, $exception, $storageFailure);

            return;
        }

        $this->announceExecutionFailure($execution, $exception, $error, $failedAt);
    }

    /**
     * The one place an exception becomes text Laraflow retains:
     * redacted first (laraflow.errors.redactor), then truncated. Every
     * persisted error column, attempt-history row, log record, and
     * failure event goes through here, so nothing the redactor strips
     * can leak out a side door. The thrown exception is untouched.
     */
    public function errorMessage(Throwable $exception): string
    {
        return mb_substr(
            $this->redactor->redact($exception, $exception->getMessage()),
            0,
            $this->maxErrorLength,
        );
    }

    /**
     * The identifiers every lifecycle event carries, spread into its
     * constructor. Collecting them here is what makes "every event
     * carries them" structural rather than a thing each call site has
     * to remember.
     *
     * @return array{executionId: int|string, workflow: string, correlationId: string|null, tenantId: string|null}
     */
    public function identity(WorkflowExecution $execution): array
    {
        return [
            'executionId' => $execution->id,
            'workflow' => $execution->workflow,
            'correlationId' => $execution->correlationId,
            'tenantId' => $execution->tenantId,
        ];
    }

    public function emit(object $event): void
    {
        $this->events?->emit($event);
    }

    protected function attempt(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        StepAttemptStatus $status,
        string $error,
        Throwable $exception,
        DateTimeImmutable $endedAt,
        ?DateTimeImmutable $nextRetryAt = null,
    ): StepAttempt {
        return new StepAttempt(
            executionId: $execution->id,
            stepExecutionId: $stepExecution->id,
            attempt: $stepExecution->attempts,
            status: $status,
            error: $error,
            errorClass: $exception::class,
            startedAt: $stepExecution->startedAt,
            endedAt: $endedAt,
            nextRetryAt: $nextRetryAt,
        );
    }

    /**
     * The attempt record that went to history feeds the event, so the
     * two can never disagree about how long the step took.
     */
    protected function announceStepFailure(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        int $attempt,
        Throwable $exception,
        string $error,
        StepAttempt $record,
    ): void {
        $this->emit(new WorkflowStepFailed(
            ...$this->identity($execution),
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $attempt,
            errorClass: $exception::class,
            error: $this->eventError($error),
            durationMs: $record->durationMs(),
        ));
    }

    protected function announceExecutionFailure(
        WorkflowExecution $execution,
        Throwable $exception,
        string $error,
        DateTimeImmutable $failedAt,
    ): void {
        $this->emit(new WorkflowExecutionFailed(
            ...$this->identity($execution),
            errorClass: $exception::class,
            error: $this->eventError($error),
            failedAt: $failedAt,
            durationMs: $execution->durationMs(),
        ));
    }

    protected function eventError(string $error): ?string
    {
        return $this->includeErrorMessages ? $error : null;
    }

    /**
     * Storage failures keep their raw messages: they are database
     * driver errors, not step data, and the redactor governs the
     * latter.
     */
    protected function logStorageFailure(
        string $what,
        WorkflowExecution $execution,
        ?StepExecution $stepExecution,
        Throwable $exception,
        Throwable $storageFailure,
    ): void {
        $this->logger->error("Laraflow could not persist {$what}.", [
            'execution_id' => $execution->id,
            ...($stepExecution === null ? [] : [
                'step' => $stepExecution->step,
                'position' => $stepExecution->position,
            ]),
            // The step's exception is redacted, as every retained
            // message is. The storage error is not: it is a database
            // driver error, not step data.
            'original_exception' => $exception::class.': '.$this->errorMessage($exception),
            'storage_exception' => $storageFailure::class.': '.$storageFailure->getMessage(),
        ]);
    }
}
