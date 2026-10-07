<?php

namespace Laraflow\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepStatus;

class StepExecutionTest extends TestCase
{
    public function test_it_starts_as_pending(): void
    {
        $step = new StepExecution(
            id: 1,
            executionId: 100,
            step: 'CreateCustomer',
            position: 0,
        );

        $this->assertTrue($step->isPending());
        $this->assertSame(
            StepStatus::Pending,
            $step->status
        );
        $this->assertSame(0, $step->attempts);
    }

    public function test_it_can_store_output(): void
    {
        $step = new StepExecution(
            id: 1,
            executionId: 100,
            step: 'CreateCustomer',
            position: 0,
        );

        $step->status = StepStatus::Completed;
        $step->attempts = 1;
        $step->output = ['customer_id' => 123];

        $this->assertTrue($step->isCompleted());
        $this->assertSame(
            ['customer_id' => 123],
            $step->output
        );
    }
}