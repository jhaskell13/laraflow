<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\InvalidStateTransitionException;

/**
 * Read-only handle describing one step within a workflow execution.
 *
 * State changes must go through the mark*() transition methods, which
 * enforce the step state machine:
 *
 *   pending → running → completed
 *   pending → skipped (conditional step, condition false)
 *   running → waiting → completed (wait resolved) | failed | retrying (wait timed out)
 *   pending → running → failed → running (retry on re-execute)
 *   running → retrying → running (scheduled retry, once due)
 *   running → running (interrupted step re-run after worker loss)
 *
 * A step found in `running` during recovery is treated as interrupted:
 * it may already have performed its side effects once, and will run
 * again. This is the at-least-once boundary.
 */
class StepExecution
{
    public function __construct(
        public readonly int|string $id,
        public readonly int|string $executionId,
        public readonly string $step,
        public readonly int $position,
        public StepStatus $status = StepStatus::Pending,
        public int $attempts = 0,
        public readonly mixed $input = null,
        public mixed $output = null,
        public ?string $error = null,
        public ?string $errorClass = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $failedAt = null,
        public ?DateTimeImmutable $timeoutAt = null,
        public ?DateTimeImmutable $nextRetryAt = null,
        public ?string $waitSignal = null,
    ) {}

    /**
     * @param  DateTimeImmutable|null  $timeoutAt  Deadline for this attempt
     *                                             (started_at plus the step's timeout), or null when the step
     *                                             declares none. Recorded before the step runs so a worker that
     *                                             dies can still be judged against it.
     */
    public function markRunning(DateTimeImmutable $at, ?DateTimeImmutable $timeoutAt = null): void
    {
        $this->assertTransition(StepStatus::Running, [
            StepStatus::Pending,
            StepStatus::Failed,
            StepStatus::Retrying,
            StepStatus::Running,
        ]);

        $this->status = StepStatus::Running;
        $this->attempts++;
        $this->startedAt = $at;
        $this->timeoutAt = $timeoutAt;
        $this->nextRetryAt = null;
    }

    public function markCompleted(mixed $output, DateTimeImmutable $at): void
    {
        $this->assertTransition(StepStatus::Completed, [
            StepStatus::Running,
            // A resolved wait completes the step; handle() never reruns.
            StepStatus::Waiting,
        ]);

        $this->status = StepStatus::Completed;
        $this->output = $output;
        $this->completedAt = $at;
        $this->error = null;
        $this->errorClass = null;
        $this->failedAt = null;
        // Live wait state clears: the signal name, and a consumed due
        // time that would otherwise read as a pending schedule.
        // timeout_at stays — the last attempt's deadline is part of its
        // record, as the timeout tests pin.
        $this->waitSignal = null;
        $this->nextRetryAt = null;
    }

    /**
     * handle() returned a Wait: park the step with everything a resume
     * needs durable — the signal name, a timed wait's resume instant in
     * next_retry_at, a signal wait's deadline in timeout_at. The open
     * attempt stays open; it closes when the wait resolves.
     */
    public function markWaiting(
        ?string $signal,
        ?DateTimeImmutable $resumeAt,
        ?DateTimeImmutable $timeoutAt
    ): void {
        $this->assertTransition(StepStatus::Waiting, [
            StepStatus::Running,
        ]);

        $this->status = StepStatus::Waiting;
        $this->waitSignal = $signal;
        $this->nextRetryAt = $resumeAt;
        $this->timeoutAt = $timeoutAt;
        // A wait starts clean: output is where a signal's payload (or a
        // child's outcome) lands, and a stale payload from a previous,
        // already-charged attempt must not read as this wait's answer.
        $this->output = null;
    }

    /**
     * A conditional step's condition answered false. Terminal for the
     * execution's lifetime: attempts stay untouched, and recovery
     * treats the step like a completed one.
     */
    public function markSkipped(DateTimeImmutable $at): void
    {
        $this->assertTransition(StepStatus::Skipped, [
            StepStatus::Pending,
            // A re-executed step whose condition answers false now: the
            // earlier failure may have been the condition itself throwing.
            StepStatus::Failed,
            StepStatus::Retrying,
        ]);

        $this->status = StepStatus::Skipped;
        $this->completedAt = $at;
    }

    public function markFailed(string $error, string $errorClass, DateTimeImmutable $at): void
    {
        $this->assertTransition(StepStatus::Failed, [
            StepStatus::Running,
            // A wait that timed out, when its policy refuses a retry.
            StepStatus::Waiting,
        ]);

        $this->status = StepStatus::Failed;
        $this->error = $error;
        $this->errorClass = $errorClass;
        $this->failedAt = $at;
    }

    /**
     * This attempt failed and the step's retry policy scheduled another.
     */
    public function markRetrying(
        string $error,
        string $errorClass,
        DateTimeImmutable $at,
        DateTimeImmutable $nextRetryAt
    ): void {
        $this->assertTransition(StepStatus::Retrying, [
            StepStatus::Running,
            // A wait that timed out, when its policy allows another go.
            StepStatus::Waiting,
        ]);

        $this->status = StepStatus::Retrying;
        $this->error = $error;
        $this->errorClass = $errorClass;
        $this->failedAt = $at;
        $this->nextRetryAt = $nextRetryAt;
    }

    /**
     * @internal The mutable fields a completion changes, captured so a
     * checkpoint that fails after markCompleted() can put the handle
     * back and let the ordinary failure paths run from a legal state.
     *
     * @return array{status: StepStatus, output: mixed, completedAt: ?DateTimeImmutable, error: ?string, errorClass: ?string, failedAt: ?DateTimeImmutable, waitSignal: ?string, nextRetryAt: ?DateTimeImmutable}
     */
    public function snapshot(): array
    {
        return [
            'status' => $this->status,
            'output' => $this->output,
            'completedAt' => $this->completedAt,
            'error' => $this->error,
            'errorClass' => $this->errorClass,
            'failedAt' => $this->failedAt,
            'waitSignal' => $this->waitSignal,
            'nextRetryAt' => $this->nextRetryAt,
        ];
    }

    /**
     * @internal Counterpart of snapshot().
     *
     * @param  array{status: StepStatus, output: mixed, completedAt: ?DateTimeImmutable, error: ?string, errorClass: ?string, failedAt: ?DateTimeImmutable, waitSignal: ?string, nextRetryAt: ?DateTimeImmutable}  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->status = $snapshot['status'];
        $this->output = $snapshot['output'];
        $this->completedAt = $snapshot['completedAt'];
        $this->error = $snapshot['error'];
        $this->errorClass = $snapshot['errorClass'];
        $this->failedAt = $snapshot['failedAt'];
        $this->waitSignal = $snapshot['waitSignal'];
        $this->nextRetryAt = $snapshot['nextRetryAt'];
    }

    public function isPending(): bool
    {
        return $this->status === StepStatus::Pending;
    }

    public function isRunning(): bool
    {
        return $this->status === StepStatus::Running;
    }

    public function isCompleted(): bool
    {
        return $this->status === StepStatus::Completed;
    }

    public function isSkipped(): bool
    {
        return $this->status === StepStatus::Skipped;
    }

    public function isWaiting(): bool
    {
        return $this->status === StepStatus::Waiting;
    }

    public function isFailed(): bool
    {
        return $this->status === StepStatus::Failed;
    }

    public function isRetrying(): bool
    {
        return $this->status === StepStatus::Retrying;
    }

    /**
     * @param  StepStatus[]  $allowedFrom
     */
    protected function assertTransition(StepStatus $to, array $allowedFrom): void
    {
        if (! in_array($this->status, $allowedFrom, true)) {
            throw InvalidStateTransitionException::between('step', $this->status, $to);
        }
    }
}
