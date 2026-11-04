<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Exceptions\SuggestsRetryDelay;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\RecordedFailure;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Writes down how a step or an execution ended, and announces it.
 *
 * Completion is one checkpoint (`completeStep`), and a failure is
 * settled one way wherever it arose — thrown from handle(), classified
 * at recovery, or resolved from a wait — by `settle()`, which hands it
 * to the first three failure outcomes below; the fourth is the loop's
 * own, for a `Continue` run's first failure. Their storage-failure
 * behaviour differs on purpose — the differences are the contract, not
 * an inconsistency:
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
final class OutcomeRecorder
{
    /** What a `text` column holds on MySQL and MariaDB. */
    public const ERROR_COLUMN_BYTES = 65535;

    protected ClockInterface $clock;

    protected ErrorRedactor $redactor;

    public function __construct(
        protected ExecutionRepository $repository,
        protected LoggerInterface $logger,
        protected EventEmitter $events,
        ClockInterface $clock,
        ErrorRedactor $redactor,
        protected ParentNotifier $parents,
        protected int $maxErrorLength = 65535,
        protected bool $includeErrorMessages = true,
        protected int $maxSuggestedRetryDelaySeconds = 3600,
    ) {
        $this->clock = $clock;
        $this->redactor = $redactor;
    }

    /**
     * Complete a step — fresh from handle(), or by resolving its wait —
     * in one claim-guarded checkpoint: the step row, the context, and
     * the attempt row that closes this handle() run, whose duration
     * spans run and park alike. The event carries the same attempt
     * record that went to history, so the two can never disagree about
     * how long the step took.
     */
    public function completeStep(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        WorkflowContext $context,
        mixed $output,
    ): void {
        $completedAt = $this->clock->now();
        $before = $stepExecution->snapshot();
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

        try {
            $this->repository->checkpoint($execution, $stepExecution, $attempt);
        } catch (Throwable $checkpointFailure) {
            // The handle said "completed" a moment early. Put it back,
            // so the failure paths that follow run from a legal state and
            // record the real error rather than an illegal transition.
            $stepExecution->restore($before);

            throw $checkpointFailure;
        }

        $this->emit(new WorkflowStepCompleted(
            ...$this->identity($execution),
            step: $stepExecution->step,
            position: $stepExecution->position,
            attempt: $stepExecution->attempts,
            completedAt: $completedAt,
            durationMs: $attempt->durationMs(),
        ));
    }

    /**
     * One step failure, settled the one way every failure is: a retry
     * if the step's policy grants one (the claim is released and the
     * handle describes the schedule), a recorded failure the loop walks
     * past under FailurePolicy::Continue, or the execution's terminal
     * failure, thrown. Legal from Running and from Waiting alike.
     */
    public function settle(
        WorkflowExecution $execution,
        StepExecution $stepExecution,
        StepPolicy $policy,
        FailurePolicy $failurePolicy,
        Throwable $exception,
        StepAttemptStatus $attemptStatus = StepAttemptStatus::Failed,
    ): StepResolution {
        if ($this->scheduleRetry($execution, $stepExecution, $policy, $exception, $attemptStatus)) {
            return StepResolution::settled();
        }

        if ($failurePolicy === FailurePolicy::Continue) {
            $this->stepFailure($execution, $stepExecution, $exception, $attemptStatus);

            return StepResolution::continueWith($exception);
        }

        // The step's failure is durable now; the execution's is not,
        // because what happens between them is the compensation pass.
        // Returned rather than thrown so the runner — which holds the
        // claim and the workflow — finishes the execution itself.
        $this->stepFailure($execution, $stepExecution, $exception, $attemptStatus);

        return StepResolution::failedWith($exception);
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
            $stepExecution->markRetrying($error, $this->errorClass($exception), $failedAt, $nextRetryAt);
            $this->repository->saveStep($execution, $stepExecution, $record);

            $execution->markRetrying($error, $this->errorClass($exception), $nextRetryAt);
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
            errorClass: $this->errorClass($exception),
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
            $stepExecution->markFailed($error, $this->errorClass($exception), $failedAt);
            $this->repository->saveStep($execution, $stepExecution, $record);
        } catch (ClaimLostException $lost) {
            throw $lost;
        } catch (Throwable $storageFailure) {
            $this->logStorageFailure('a step failure', $execution, $stepExecution, $exception, $storageFailure);

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
            $stepExecution->markFailed($error, $this->errorClass($exception), $failedAt);
            $this->repository->saveStep($execution, $stepExecution, $record);

            $execution->markFailed($error, $this->errorClass($exception), $failedAt);
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
        $this->parents->childFinished($execution);
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
            $execution->markFailed($error, $this->errorClass($exception), $failedAt);
            $this->repository->release($execution);
        } catch (ClaimLostException $lost) {
            throw $lost;
        } catch (Throwable $storageFailure) {
            $this->logStorageFailure('a workflow failure', $execution, null, $exception, $storageFailure);

            return;
        }

        $this->announceExecutionFailure($execution, $exception, $error, $failedAt);
        $this->parents->childFinished($execution);
    }

    /**
     * Rest a compensated execution on the failure it has been carrying
     * since the pass began. The exception object is gone — the pass may
     * have spanned claims — but the message and class were recorded
     * with the status, so the record is the same either way.
     *
     * The stand-in is a RecordedFailure rather than a bare
     * WorkflowException, so the class reaches the event and the log
     * line as well as the column. Rebuilding it as the wrapper's own
     * class would have made every compensated execution alert as a
     * WorkflowException whatever actually broke, while its row said
     * otherwise.
     */
    public function failRecorded(WorkflowExecution $execution): void
    {
        $error = $execution->error ?? 'The execution failed.';
        $errorClass = $execution->errorClass ?? WorkflowException::class;
        $failedAt = $this->clock->now();
        $recorded = new RecordedFailure($error, $errorClass);

        try {
            $execution->markFailed($error, $errorClass, $failedAt);
            $this->repository->release($execution);
        } catch (ClaimLostException $lost) {
            throw $lost;
        } catch (Throwable $storageFailure) {
            $this->logStorageFailure('a compensated workflow failure', $execution, null, $recorded, $storageFailure);

            return;
        }

        $this->announceExecutionFailure($execution, $recorded, $error, $failedAt);
        $this->parents->childFinished($execution);
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
        // Characters first (the configured bound), then bytes: the
        // `error` columns are `text`, which on MySQL and MariaDB holds
        // 65,535 bytes, not characters — a long non-ASCII message would
        // otherwise fail the very write that records the failure.
        return mb_strcut(
            mb_substr($this->redactor->redact($exception, $exception->getMessage()), 0, $this->maxErrorLength),
            0,
            self::ERROR_COLUMN_BYTES,
        );
    }

    /**
     * The class name every error_class column and event receives. An
     * anonymous class's name carries a NUL byte and the defining file's
     * path — PostgreSQL refuses the NUL outright, and the rest can
     * exceed the column — so it is cut at the marker PHP puts there.
     */
    public function errorClass(Throwable $exception): string
    {
        // A failure rebuilt for a resumed compensation pass reports the
        // class it stands for, so error_class survives the rebuild.
        if ($exception instanceof RecordedFailure) {
            return $exception->recordedClass();
        }

        $class = $exception::class;
        $anonymous = strpos($class, '@anonymous');

        if ($anonymous !== false) {
            $class = substr($class, 0, $anonymous + strlen('@anonymous'));
        }

        return mb_substr($class, 0, 255);
    }

    /**
     * The identifiers every lifecycle event carries, spread into its
     * constructor. Collecting them here is what makes "every event
     * carries them" structural rather than a thing each call site has
     * to remember.
     *
     * @return array{executionId: int, workflow: string, correlationId: string|null, tenantId: string|null}
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
        $this->events->emit($event);
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
            errorClass: $this->errorClass($exception),
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
            errorClass: $this->errorClass($exception),
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
            errorClass: $this->errorClass($exception),
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
