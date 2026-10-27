<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Queue;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laraflow\Events\WorkflowExecutionDispatched;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\ExecutionNotRunnableException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Tests\Support\MutableWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowRunner;
use stdClass;

class WorkflowDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    // ------------------------------------------------------------------
    // dispatch()
    // ------------------------------------------------------------------

    public function test_dispatch_persists_the_execution_and_plan_without_running_anything(): void
    {
        $execution = $this->dispatcher()->dispatch(new TwoStepWorkflow, ['customer_id' => 42]);

        $this->assertTrue($execution->isPending());
        $this->assertSame(['customer_id' => 42], $execution->input);

        $this->assertSame(0, StepOne::$runs);
        $this->assertSame(0, StepTwo::$runs);

        $steps = StepExecutionModel::query()->orderBy('position')->get();
        $this->assertCount(2, $steps);
        $this->assertSame(StepStatus::Pending->value, $this->row($steps, 0, 'step')->status);
    }

    public function test_dispatch_queues_a_job_carrying_only_the_execution_id(): void
    {
        $execution = $this->dispatcher()->dispatch(new TwoStepWorkflow, ['secret' => 'tok_abc']);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $execution->id,
        );

        // The payload must not carry workflow input; the worker reads
        // durable state instead.
        Queue::assertPushed(ExecuteWorkflowJob::class, function (ExecuteWorkflowJob $job): bool {
            return ! str_contains(serialize($job), 'tok_abc');
        });
    }

    public function test_dispatch_applies_the_configured_connection_and_queue(): void
    {
        config([
            'laraflow.queue.connection' => 'redis',
            'laraflow.queue.queue' => 'workflows',
        ]);

        $this->dispatcher()->dispatch(new TwoStepWorkflow);

        Queue::assertPushed(ExecuteWorkflowJob::class, function (ExecuteWorkflowJob $job): bool {
            return $job->connection === 'redis' && $job->queue === 'workflows';
        });
    }

    public function test_per_call_connection_and_queue_override_the_configuration(): void
    {
        config([
            'laraflow.queue.connection' => 'redis',
            'laraflow.queue.queue' => 'workflows',
        ]);

        $this->dispatcher()->dispatch(
            new TwoStepWorkflow,
            connection: 'sqs',
            queue: 'urgent',
        );

        Queue::assertPushed(ExecuteWorkflowJob::class, function (ExecuteWorkflowJob $job): bool {
            return $job->connection === 'sqs' && $job->queue === 'urgent';
        });
    }

    public function test_unconfigured_connection_and_queue_leave_laravel_defaults_in_place(): void
    {
        $this->dispatcher()->dispatch(new TwoStepWorkflow);

        Queue::assertPushed(ExecuteWorkflowJob::class, function (ExecuteWorkflowJob $job): bool {
            return $job->connection === null && $job->queue === null;
        });
    }

    public function test_jobs_are_dispatched_after_commit_by_default(): void
    {
        $this->dispatcher()->dispatch(new TwoStepWorkflow);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->afterCommit === true,
        );
    }

    public function test_after_commit_can_be_disabled(): void
    {
        config(['laraflow.queue.after_commit' => false]);

        $this->dispatcher()->dispatch(new TwoStepWorkflow);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->afterCommit !== true,
        );
    }

    public function test_the_job_carries_the_configured_retry_settings(): void
    {
        config([
            'laraflow.queue.tries' => 3,
            'laraflow.queue.timeout' => 120,
            'laraflow.queue.backoff' => 30,
        ]);

        $this->dispatcher()->dispatch(new TwoStepWorkflow);

        Queue::assertPushed(ExecuteWorkflowJob::class, function (ExecuteWorkflowJob $job): bool {
            return $job->tries === 3 && $job->timeout === 120 && $job->backoff === 30;
        });
    }

    public function test_the_queue_does_not_retry_by_default(): void
    {
        $this->dispatcher()->dispatch(new TwoStepWorkflow);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->tries === 1,
        );
    }

    public function test_dispatch_fires_the_dispatched_event(): void
    {
        Event::fake([WorkflowExecutionDispatched::class]);

        config(['laraflow.queue.queue' => 'workflows']);

        $execution = $this->dispatcher()->dispatch(new TwoStepWorkflow);

        Event::assertDispatched(
            WorkflowExecutionDispatched::class,
            function (WorkflowExecutionDispatched $event) use ($execution): bool {
                return $event->executionId === $execution->id
                    && $event->workflow === TwoStepWorkflow::class
                    && $event->queue === 'workflows';
            }
        );
    }

    // ------------------------------------------------------------------
    // Nothing is queued when the execution is never created
    // ------------------------------------------------------------------

    public function test_an_invalid_definition_queues_nothing(): void
    {
        MutableWorkflow::$steps = [];

        $this->expectException(InvalidWorkflowDefinitionException::class);

        try {
            $this->dispatcher()->dispatch(new MutableWorkflow);
        } finally {
            Queue::assertNothingPushed();
            $this->assertSame(0, WorkflowExecutionModel::query()->count());
        }
    }

    public function test_unserializable_input_queues_nothing(): void
    {
        $this->expectException(UnserializableValueException::class);

        try {
            $this->dispatcher()->dispatch(new TwoStepWorkflow, ['bad' => new stdClass]);
        } finally {
            Queue::assertNothingPushed();
            $this->assertSame(0, WorkflowExecutionModel::query()->count());
        }
    }

    // ------------------------------------------------------------------
    // dispatchExecution()
    // ------------------------------------------------------------------

    public function test_dispatch_execution_queues_an_existing_pending_execution(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        $queued = $this->dispatcher()->dispatchExecution($execution->id);

        $this->assertSame($execution->id, $queued->id);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $execution->id,
        );
    }

    public function test_dispatch_execution_accepts_a_running_execution_so_stale_work_can_be_recovered(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'status' => ExecutionStatus::Running->value,
        ]);

        $this->dispatcher()->dispatchExecution($execution->id);

        Queue::assertPushed(ExecuteWorkflowJob::class);
    }

    public function test_dispatch_execution_refuses_a_completed_execution(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $this->expectException(ExecutionNotRunnableException::class);

        try {
            $this->dispatcher()->dispatchExecution($execution->id);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    public function test_dispatch_execution_rejects_an_unknown_id(): void
    {
        $this->expectException(ExecutionNotFoundException::class);

        try {
            $this->dispatcher()->dispatchExecution(404);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function dispatcher(): WorkflowDispatcher
    {
        // Resolved fresh so config() changes made inside a test apply.
        return $this->app->make(WorkflowDispatcher::class);
    }
}
