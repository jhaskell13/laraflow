<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\InvalidStateTransitionException;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StepExecutionTest extends TestCase
{
    public function test_it_starts_as_pending(): void
    {
        $step = $this->step();

        $this->assertTrue($step->isPending());
        $this->assertSame(StepStatus::Pending, $step->status);
        $this->assertSame(0, $step->attempts);
    }

    public function test_running_increments_attempts(): void
    {
        $step = $this->step();
        $now = new DateTimeImmutable;

        $step->markRunning($now);

        $this->assertTrue($step->isRunning());
        $this->assertSame(1, $step->attempts);
        $this->assertSame($now, $step->startedAt);
    }

    public function test_it_completes_with_output(): void
    {
        $step = $this->step();
        $step->markRunning(new DateTimeImmutable);

        $step->markCompleted(['customer_id' => 123], new DateTimeImmutable);

        $this->assertTrue($step->isCompleted());
        $this->assertSame(['customer_id' => 123], $step->output);
        $this->assertNotNull($step->completedAt);
    }

    public function test_it_fails_with_error_details(): void
    {
        $step = $this->step();
        $step->markRunning(new DateTimeImmutable);

        $step->markFailed('boom', RuntimeException::class, new DateTimeImmutable);

        $this->assertTrue($step->isFailed());
        $this->assertSame('boom', $step->error);
        $this->assertSame(RuntimeException::class, $step->errorClass);
    }

    public function test_a_failed_step_can_retry_and_attempts_accumulate(): void
    {
        $step = $this->step();
        $step->markRunning(new DateTimeImmutable);
        $step->markFailed('boom', RuntimeException::class, new DateTimeImmutable);

        $step->markRunning(new DateTimeImmutable);

        $this->assertTrue($step->isRunning());
        $this->assertSame(2, $step->attempts);
    }

    public function test_an_interrupted_running_step_can_run_again(): void
    {
        $step = $this->step();
        $step->markRunning(new DateTimeImmutable);

        // Worker died; a recovering worker runs the step again.
        $step->markRunning(new DateTimeImmutable);

        $this->assertTrue($step->isRunning());
        $this->assertSame(2, $step->attempts);
    }

    public function test_completing_clears_a_previous_error(): void
    {
        $step = $this->step();
        $step->markRunning(new DateTimeImmutable);
        $step->markFailed('boom', RuntimeException::class, new DateTimeImmutable);
        $step->markRunning(new DateTimeImmutable);

        $step->markCompleted(null, new DateTimeImmutable);

        $this->assertNull($step->error);
        $this->assertNull($step->errorClass);
    }

    public function test_it_cannot_complete_without_running(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        $this->step()->markCompleted(null, new DateTimeImmutable);
    }

    public function test_a_completed_step_is_terminal(): void
    {
        $step = $this->step();
        $step->markRunning(new DateTimeImmutable);
        $step->markCompleted(null, new DateTimeImmutable);

        $this->expectException(InvalidStateTransitionException::class);

        $step->markRunning(new DateTimeImmutable);
    }

    protected function step(): StepExecution
    {
        return new StepExecution(
            id: 1,
            executionId: 100,
            step: 'CreateCustomer',
            position: 0,
        );
    }
}
