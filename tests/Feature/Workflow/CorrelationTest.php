<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionDispatched;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * Correlation and tenant identifiers: recorded at creation, readable by
 * steps from durable state, and carried on every lifecycle event.
 *
 * The point of the feature is tying an execution to the rest of an
 * application's telemetry, so "it reaches every sink" is the behaviour
 * under test, not just "it is stored".
 */
class CorrelationTest extends TestCase
{
    protected const OBSERVED_EVENTS = [
        WorkflowExecutionDispatched::class,
        WorkflowExecutionStarted::class,
        WorkflowStepStarted::class,
        WorkflowStepCompleted::class,
        WorkflowStepFailed::class,
        WorkflowExecutionCompleted::class,
        WorkflowExecutionFailed::class,
        WorkflowExecutionCancelled::class,
    ];

    /** @var list<object> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = [];

        foreach (self::OBSERVED_EVENTS as $class) {
            Event::listen($class, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    // ------------------------------------------------------------------
    // Recording
    // ------------------------------------------------------------------

    public function test_start_records_the_identifiers_on_the_execution(): void
    {
        $execution = $this->runner()->start(
            new TwoStepWorkflow,
            ['customer_id' => 42],
            new ExecutionOptions('req-abc123', 'acme'),
        );

        $this->assertSame('req-abc123', $execution->correlationId);
        $this->assertSame('acme', $execution->tenantId);

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertSame('req-abc123', $row->correlation_id);
        $this->assertSame('acme', $row->tenant_id);
    }

    public function test_the_identifiers_survive_a_reload_through_the_repository(): void
    {
        $execution = $this->runner()->start(
            new TwoStepWorkflow,
            [],
            new ExecutionOptions('req-abc123', 'acme'),
        );

        $reloaded = $this->app->make(ExecutionRepository::class)->find($execution->id);

        $this->assertSame('req-abc123', $reloaded->correlationId);
        $this->assertSame('acme', $reloaded->tenantId);
    }

    public function test_omitting_the_options_records_nothing(): void
    {
        $execution = $this->runner()->run(new TwoStepWorkflow);

        $this->assertNull($execution->correlationId);
        $this->assertNull($execution->tenantId);

        // Existing callers are unaffected: the whole feature is opt-in.
        $this->assertNull(WorkflowExecutionModel::query()->sole()->correlation_id);
    }

    public function test_dispatch_records_the_identifiers_before_queueing(): void
    {
        $execution = $this->app->make(WorkflowDispatcher::class)->dispatch(
            new TwoStepWorkflow,
            [],
            null,
            null,
            new ExecutionOptions('req-abc123', 'acme'),
        );

        $this->assertSame('req-abc123', $execution->correlationId);
        $this->assertSame('acme', WorkflowExecutionModel::query()->sole()->tenant_id);
    }

    // ------------------------------------------------------------------
    // Visibility to steps
    // ------------------------------------------------------------------

    public function test_a_step_reads_the_identifiers_from_the_context(): void
    {
        $this->runner()->run(
            new TwoStepWorkflow,
            [],
            new ExecutionOptions('req-abc123', 'acme'),
        );

        $this->assertSame('req-abc123', StepOne::$seenCorrelationId);
        $this->assertSame('acme', StepOne::$seenTenantId);
    }

    public function test_a_step_sees_nulls_when_nothing_was_supplied(): void
    {
        $this->runner()->run(new TwoStepWorkflow);

        $this->assertNull(StepOne::$seenCorrelationId);
        $this->assertNull(StepOne::$seenTenantId);
    }

    public function test_a_recovered_step_sees_the_original_identifiers(): void
    {
        StepTwo::$failTimes = 1;

        $execution = $this->runner()->start(
            new TwoStepWorkflow,
            [],
            new ExecutionOptions('req-abc123', 'acme'),
        );

        try {
            $this->runner()->execute($execution->id, new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected: step two fails on its first attempt.
        }

        StepOne::$seenCorrelationId = null;
        StepOne::$seenTenantId = null;

        $this->runner()->execute($execution->id, new TwoStepWorkflow);

        // Step one is skipped on recovery, so prove the identifiers came
        // from the durable record by re-reading the execution: a worker
        // recovering this has no ambient request to inherit them from.
        $reloaded = $this->app->make(ExecutionRepository::class)->find($execution->id);

        $this->assertTrue($reloaded->isCompleted());
        $this->assertSame('req-abc123', $reloaded->correlationId);
        $this->assertSame('acme', $reloaded->tenantId);
        $this->assertSame('req-abc123', StepTwo::$seenCorrelationId);
    }

    // ------------------------------------------------------------------
    // Visibility to events
    // ------------------------------------------------------------------

    public function test_every_event_of_a_successful_run_carries_the_identifiers(): void
    {
        $this->runner()->run(
            new TwoStepWorkflow,
            [],
            new ExecutionOptions('req-abc123', 'acme'),
        );

        $this->assertNotEmpty($this->events);
        $this->assertEventsCarry('req-abc123', 'acme');
    }

    public function test_every_event_of_a_failing_run_carries_the_identifiers(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(
                new TwoStepWorkflow,
                [],
                new ExecutionOptions('req-abc123', 'acme'),
            );
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertContainsEvent(WorkflowStepFailed::class);
        $this->assertContainsEvent(WorkflowExecutionFailed::class);
        $this->assertEventsCarry('req-abc123', 'acme');
    }

    public function test_the_dispatch_and_cancellation_events_carry_the_identifiers(): void
    {
        // Faked so the execution stays pending: the test environment's
        // sync driver would run it to completion inside dispatch(), and
        // a completed execution cannot be cancelled.
        Queue::fake();

        $execution = $this->app->make(WorkflowDispatcher::class)->dispatch(
            new TwoStepWorkflow,
            [],
            null,
            null,
            new ExecutionOptions('req-abc123', 'acme'),
        );

        $this->runner()->cancel($execution->id, 'customer withdrew');

        $this->assertContainsEvent(WorkflowExecutionDispatched::class);
        $this->assertContainsEvent(WorkflowExecutionCancelled::class);
        $this->assertEventsCarry('req-abc123', 'acme');
    }

    public function test_events_carry_nulls_when_nothing_was_supplied(): void
    {
        $this->runner()->run(new TwoStepWorkflow);

        $this->assertNotEmpty($this->events);
        $this->assertEventsCarry(null, null);
    }

    /**
     * Every captured event must expose both identifiers. Asserting over
     * the whole captured set rather than a chosen few means a newly
     * added event class cannot quietly omit them.
     */
    protected function assertEventsCarry(?string $correlationId, ?string $tenantId): void
    {
        foreach ($this->events as $event) {
            $carried = get_object_vars($event);

            $this->assertArrayHasKey('correlationId', $carried, $event::class.' must carry a correlation id.');
            $this->assertArrayHasKey('tenantId', $carried, $event::class.' must carry a tenant id.');
            $this->assertSame($correlationId, $carried['correlationId'], $event::class.' carried the wrong correlation id.');
            $this->assertSame($tenantId, $carried['tenantId'], $event::class.' carried the wrong tenant id.');
        }
    }

    protected function assertContainsEvent(string $class): void
    {
        $this->assertNotEmpty(
            array_filter($this->events, fn (object $event): bool => $event instanceof $class),
            "Expected a {$class} event.",
        );
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
