<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\InvalidStateTransitionException;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\WorkflowExecution;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkflowExecutionTest extends TestCase
{
    public function test_it_starts_as_pending(): void
    {
        $execution = $this->execution();

        $this->assertTrue($execution->isPending());
        $this->assertSame(ExecutionStatus::Pending, $execution->status);
    }

    public function test_it_transitions_from_pending_to_running(): void
    {
        $execution = $this->execution();
        $now = new DateTimeImmutable;

        $execution->markRunning($now);

        $this->assertTrue($execution->isRunning());
        $this->assertSame($now, $execution->startedAt);
    }

    public function test_running_keeps_the_original_started_at(): void
    {
        $execution = $this->execution();
        $first = new DateTimeImmutable('-1 hour');

        $execution->markRunning($first);
        $execution->markRunning(new DateTimeImmutable);

        $this->assertSame($first, $execution->startedAt);
    }

    public function test_it_completes_from_running(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable);

        $execution->markCompleted(new DateTimeImmutable);

        $this->assertTrue($execution->isCompleted());
        $this->assertNotNull($execution->completedAt);
        $this->assertNull($execution->error);
    }

    public function test_it_fails_from_running_and_records_the_error(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable);

        $execution->markFailed('boom', RuntimeException::class, new DateTimeImmutable);

        $this->assertTrue($execution->isFailed());
        $this->assertSame('boom', $execution->error);
        $this->assertSame(RuntimeException::class, $execution->errorClass);
        $this->assertNotNull($execution->failedAt);
    }

    public function test_a_failed_execution_can_run_again(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable);
        $execution->markFailed('boom', RuntimeException::class, new DateTimeImmutable);

        $execution->markRunning(new DateTimeImmutable);

        $this->assertTrue($execution->isRunning());
    }

    public function test_completing_resets_a_previous_error(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable);
        $execution->markFailed('boom', RuntimeException::class, new DateTimeImmutable);
        $execution->markRunning(new DateTimeImmutable);

        $execution->markCompleted(new DateTimeImmutable);

        $this->assertNull($execution->error);
        $this->assertNull($execution->errorClass);
    }

    public function test_a_running_execution_can_schedule_a_retry(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable);
        $nextRetryAt = new DateTimeImmutable('+30 seconds');

        $execution->markRetrying('boom', RuntimeException::class, $nextRetryAt);

        $this->assertTrue($execution->isRetrying());
        $this->assertSame($nextRetryAt, $execution->nextRetryAt);
        $this->assertSame('boom', $execution->error);
        $this->assertSame(RuntimeException::class, $execution->errorClass);
        $this->assertNull($execution->failedAt, 'A scheduled retry is not a terminal failure.');
    }

    public function test_a_retrying_execution_runs_again_and_clears_its_schedule(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable);
        $execution->markRetrying('boom', RuntimeException::class, new DateTimeImmutable('+30 seconds'));

        $execution->markRunning(new DateTimeImmutable);

        $this->assertTrue($execution->isRunning());
        $this->assertNull(
            $execution->nextRetryAt,
            'A stale due time would make the scan pick up a running execution.',
        );
    }

    public function test_a_retry_cannot_be_scheduled_unless_running(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        $this->execution()->markRetrying('boom', RuntimeException::class, new DateTimeImmutable);
    }

    public function test_every_non_terminal_state_can_be_cancelled(): void
    {
        foreach (['pending', 'running', 'failed', 'retrying'] as $state) {
            $execution = $this->executionIn($state);

            $execution->markCancelled(new DateTimeImmutable, 'operator request');

            $this->assertTrue($execution->isCancelled(), "Cancelling from [{$state}] must be allowed.");
            $this->assertNotNull($execution->cancelledAt);
            $this->assertSame('operator request', $execution->cancelReason);
            $this->assertNull($execution->nextRetryAt, 'Cancelling voids any scheduled retry.');
        }
    }

    public function test_a_completed_execution_cannot_be_cancelled(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable);
        $execution->markCompleted(new DateTimeImmutable);

        $this->expectException(InvalidStateTransitionException::class);

        $execution->markCancelled(new DateTimeImmutable);
    }

    public function test_a_cancelled_execution_is_terminal(): void
    {
        $execution = $this->execution();
        $execution->markCancelled(new DateTimeImmutable);

        $this->expectException(InvalidStateTransitionException::class);

        $execution->markRunning(new DateTimeImmutable);
    }

    public function test_it_cannot_complete_without_running(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        $this->execution()->markCompleted(new DateTimeImmutable);
    }

    public function test_it_cannot_fail_without_running(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        $this->execution()->markFailed('boom', RuntimeException::class, new DateTimeImmutable);
    }

    public function test_a_completed_execution_is_terminal(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable);
        $execution->markCompleted(new DateTimeImmutable);

        $this->expectException(InvalidStateTransitionException::class);

        $execution->markRunning(new DateTimeImmutable);
    }

    // ------------------------------------------------------------------
    // finished_at: one terminal timestamp across all three terminal states
    // ------------------------------------------------------------------

    public function test_a_new_execution_has_not_finished(): void
    {
        $this->assertNull($this->execution()->finishedAt);
    }

    public function test_each_terminal_transition_records_the_same_finished_at(): void
    {
        $at = new DateTimeImmutable;

        $completed = $this->executionIn('running');
        $completed->markCompleted($at);
        $this->assertSame($at, $completed->finishedAt);
        $this->assertSame($completed->completedAt, $completed->finishedAt);

        $failed = $this->executionIn('running');
        $failed->markFailed('boom', RuntimeException::class, $at);
        $this->assertSame($at, $failed->finishedAt);
        $this->assertSame($failed->failedAt, $failed->finishedAt);

        $cancelled = $this->executionIn('running');
        $cancelled->markCancelled($at, 'operator request');
        $this->assertSame($at, $cancelled->finishedAt);
        $this->assertSame($cancelled->cancelledAt, $cancelled->finishedAt);
    }

    public function test_a_scheduled_retry_has_not_finished(): void
    {
        $execution = $this->executionIn('retrying');

        // `retrying` is the one non-terminal state reached from running.
        // Recording a finish here would offer a waiting execution to
        // retention, which deletes it before its retry ever runs.
        $this->assertNull($execution->finishedAt);
    }

    public function test_re_executing_a_failed_execution_clears_its_finish(): void
    {
        $execution = $this->executionIn('failed');
        $this->assertNotNull($execution->finishedAt);

        $execution->markRunning(new DateTimeImmutable);

        $this->assertNull(
            $execution->finishedAt,
            'A stale finished_at would offer a running execution to retention.',
        );
    }

    // ------------------------------------------------------------------
    // isFinished(): stopped, which is not the same as never claimable
    // ------------------------------------------------------------------

    public function test_finished_covers_every_state_that_records_a_finish(): void
    {
        $this->assertTrue(ExecutionStatus::Completed->isFinished());
        $this->assertTrue(ExecutionStatus::Cancelled->isFinished());

        // Failed is re-executable — not "terminal" in the claiming
        // sense — but it has stopped, and retention asks that question.
        $this->assertTrue(ExecutionStatus::Failed->isFinished());

        $this->assertFalse(ExecutionStatus::Pending->isFinished());
        $this->assertFalse(ExecutionStatus::Running->isFinished());
        $this->assertFalse(ExecutionStatus::Retrying->isFinished());
    }

    public function test_the_handle_agrees_with_its_status(): void
    {
        $execution = $this->executionIn('failed');

        $this->assertTrue($execution->isFinished());
        $this->assertTrue($execution->isFailed());
        $this->assertNotNull($execution->finishedAt, 'A finished execution records when.');
    }

    public function test_a_status_that_has_not_finished_records_no_finish(): void
    {
        foreach (['pending', 'running', 'retrying'] as $state) {
            $execution = $this->executionIn($state);

            $this->assertFalse($execution->isFinished(), "[{$state}] has not finished.");
            $this->assertNull($execution->finishedAt);
        }
    }

    // ------------------------------------------------------------------
    // durationMs(): end-to-end latency
    // ------------------------------------------------------------------

    public function test_duration_spans_the_first_start_to_the_finish(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable('2026-10-24 12:00:00'));
        $execution->markCompleted(new DateTimeImmutable('2026-10-24 12:00:02.500'));

        $this->assertSame(2500, $execution->durationMs());
    }

    public function test_duration_includes_the_time_spent_failed(): void
    {
        $execution = $this->execution();
        $execution->markRunning(new DateTimeImmutable('2026-10-24 12:00:00'));
        $execution->markFailed('boom', RuntimeException::class, new DateTimeImmutable('2026-10-24 12:00:01'));

        // Re-executed an hour later and completing immediately.
        $execution->markRunning(new DateTimeImmutable('2026-10-24 13:00:00'));
        $execution->markCompleted(new DateTimeImmutable('2026-10-24 13:00:00'));

        // startedAt is the first start, so this is end-to-end latency —
        // the hour it sat failed is part of how long it took.
        $this->assertSame(3_600_000, $execution->durationMs());
    }

    public function test_an_unfinished_execution_has_no_duration(): void
    {
        $execution = $this->executionIn('running');

        $this->assertNull($execution->durationMs());
    }

    public function test_an_execution_cancelled_before_it_started_has_no_duration(): void
    {
        $execution = $this->execution();
        $execution->markCancelled(new DateTimeImmutable);

        // It never began, so there is no span — not a span of zero.
        $this->assertNull($execution->durationMs());
    }

    protected function execution(): WorkflowExecution
    {
        return new WorkflowExecution(
            id: 1,
            workflow: 'SyncCustomerWorkflow',
        );
    }

    protected function executionIn(string $state): WorkflowExecution
    {
        $execution = $this->execution();

        if ($state === 'pending') {
            return $execution;
        }

        $execution->markRunning(new DateTimeImmutable);

        match ($state) {
            'running' => null,
            'failed' => $execution->markFailed('boom', RuntimeException::class, new DateTimeImmutable),
            'retrying' => $execution->markRetrying('boom', RuntimeException::class, new DateTimeImmutable),
            default => throw new InvalidArgumentException("Unknown state [{$state}]."),
        };

        return $execution;
    }
}
