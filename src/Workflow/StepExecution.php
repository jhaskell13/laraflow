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
 *   running → waiting → completed (wait resolved) | failed (wait timed out)
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
        // The signal name is live wait state and clears; timeout_at and
        // next_retry_at stay — the last attempt's deadline and due time
        // are part of its record, as the timeout tests pin.
        $this->waitSignal = null;
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
