<?php

namespace Laraflow\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\WorkflowExecution;

class WorkflowExecutionTest extends TestCase
{
    public function test_it_starts_as_pending(): void
    {
        $execution = new WorkflowExecution(
            id: 1,
            workflow: 'SyncCustomerWorkflow',
        );

        $this->assertTrue($execution->isPending());
        $this->assertSame(
            ExecutionStatus::Pending,
            $execution->status
        );
    }

    public function test_it_can_be_marked_as_running(): void
    {
        $execution = new WorkflowExecution(
            id: 1,
            workflow: 'SyncCustomerWorkflow',
        );

        $execution->status = ExecutionStatus::Running;

        $this->assertTrue($execution->isRunning());
    }
}