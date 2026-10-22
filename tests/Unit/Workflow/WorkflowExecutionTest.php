<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use DateTimeImmutable;
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

    protected function execution(): WorkflowExecution
    {
        return new WorkflowExecution(
            id: 1,
            workflow: 'SyncCustomerWorkflow',
        );
    }
}
