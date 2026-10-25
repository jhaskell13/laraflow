<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Observability;

use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Laraflow\Events\WorkflowStepStarted;
use Laraflow\Support\LoggingContext;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The execution's identity in Laravel's log context: present for every
 * log record written while a step runs, gone afterwards.
 *
 * Asserted against the context repository itself rather than captured
 * log output, because that is what Laravel's log processor reads and
 * what it dehydrates into queue payloads — testing the source keeps
 * these tests honest about the mechanism rather than one of its sinks.
 */
class LoggingContextTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->make(ContextRepository::class)->flush();

        parent::tearDown();
    }

    public function test_a_step_sees_the_execution_identity_in_the_context(): void
    {
        $seen = [];

        StepOne::$beforeReturn = function () use (&$seen): void {
            $seen = Context::all();
        };

        $this->runner()->run(
            new TwoStepWorkflow,
            [],
            new ExecutionOptions('req-abc123', 'acme'),
        );

        $this->assertSame(1, $seen['laraflow.execution_id'] ?? null);
        $this->assertSame(TwoStepWorkflow::class, $seen['laraflow.workflow'] ?? null);
        $this->assertSame(StepOne::class, $seen['laraflow.step'] ?? null);
        $this->assertSame(0, $seen['laraflow.position'] ?? null);
        $this->assertSame(1, $seen['laraflow.attempt'] ?? null);
        $this->assertSame('req-abc123', $seen['laraflow.correlation_id'] ?? null);
        $this->assertSame('acme', $seen['laraflow.tenant_id'] ?? null);
    }

    public function test_the_step_keys_follow_the_step_actually_running(): void
    {
        $first = [];
        $second = [];

        StepOne::$beforeReturn = function () use (&$first): void {
            $first = Context::all();
        };
        StepTwo::$beforeRun = function () use (&$second): void {
            $second = Context::all();
        };

        $this->runner()->run(new TwoStepWorkflow);

        $this->assertSame(StepOne::class, $first['laraflow.step'] ?? null);
        $this->assertSame(0, $first['laraflow.position'] ?? null);
        $this->assertSame(StepTwo::class, $second['laraflow.step'] ?? null);
        $this->assertSame(1, $second['laraflow.position'] ?? null);
    }

    public function test_the_step_started_event_is_announced_with_its_own_step_in_context(): void
    {
        $seen = [];

        $this->app->make('events')->listen(
            WorkflowStepStarted::class,
            function (WorkflowStepStarted $event) use (&$seen): void {
                $seen[$event->position] = Context::get('laraflow.step');
            },
        );

        $this->runner()->run(new TwoStepWorkflow);

        // Set before the announcement, not after: otherwise a listener
        // logging here is attributed to the previous step, and to no
        // step at all on the first one.
        $this->assertSame(StepOne::class, $seen[0] ?? null);
        $this->assertSame(StepTwo::class, $seen[1] ?? null);
    }

    public function test_the_attempt_number_tracks_retries(): void
    {
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 0, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $attempts = [];

        StepTwo::$beforeRun = function () use (&$attempts): void {
            $attempts[] = Context::get('laraflow.attempt');
        };

        $execution = $this->runner()->run(new PolicyWorkflow);
        $this->runner()->execute($execution->id, new PolicyWorkflow, ignoreRetrySchedule: true);

        $this->assertSame([1, 2], $attempts);
    }

    public function test_absent_identifiers_are_absent_rather_than_null(): void
    {
        $seen = [];

        StepOne::$beforeReturn = function () use (&$seen): void {
            $seen = Context::all();
        };

        $this->runner()->run(new TwoStepWorkflow);

        // A null correlation id in every record is noise that means
        // nothing; the key simply should not be there.
        $this->assertArrayNotHasKey('laraflow.correlation_id', $seen);
        $this->assertArrayNotHasKey('laraflow.tenant_id', $seen);
    }

    // ------------------------------------------------------------------
    // Leaving no trace
    // ------------------------------------------------------------------

    public function test_the_context_is_clean_after_a_successful_run(): void
    {
        $this->runner()->run(new TwoStepWorkflow);

        $this->assertEmpty($this->laraflowKeys());
    }

    public function test_the_context_is_clean_after_a_failed_run(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->runner()->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Expected.
        }

        // Restored from a finally, so a throw cannot leak the execution
        // id onto every later record of the surrounding request.
        $this->assertEmpty($this->laraflowKeys());
    }

    public function test_the_context_is_clean_after_a_scheduled_retry(): void
    {
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 60, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $result = $this->runner()->run(new PolicyWorkflow);

        $this->assertTrue($result->isRetrying());
        $this->assertEmpty($this->laraflowKeys());
    }

    public function test_the_application_s_own_context_is_never_touched(): void
    {
        Context::add('request_id', 'app-owned');
        Context::add('laraflow.execution_id', 'app-owned-too');

        $this->runner()->run(new TwoStepWorkflow);

        $this->assertSame('app-owned', Context::get('request_id'));

        // Even a key in our own namespace is put back as it was, rather
        // than deleted, because we did not create it.
        $this->assertSame('app-owned-too', Context::get('laraflow.execution_id'));
    }

    public function test_a_nested_execution_restores_the_outer_one(): void
    {
        $inner = null;
        $afterInner = null;

        StepOne::$beforeReturn = function () use (&$inner, &$afterInner): void {
            // A step synchronously running another workflow.
            PolicyWorkflow::$steps = [StepTwo::class];
            $this->runner()->run(new PolicyWorkflow);

            $inner = PolicyWorkflow::class;
            $afterInner = Context::all();
        };

        $this->runner()->run(new TwoStepWorkflow);

        $this->assertNotNull($inner);

        // The outer execution's keys are back, not deleted: the rest of
        // its run must still be attributed to it.
        $this->assertSame(TwoStepWorkflow::class, $afterInner['laraflow.workflow'] ?? null);
        $this->assertSame(1, $afterInner['laraflow.execution_id'] ?? null);
        $this->assertEmpty($this->laraflowKeys());
    }

    // ------------------------------------------------------------------
    // Propagation and configuration
    // ------------------------------------------------------------------

    public function test_the_context_dehydrates_into_a_job_dispatched_by_a_step(): void
    {
        $dehydrated = null;

        StepOne::$beforeReturn = function () use (&$dehydrated): void {
            // Laravel attaches the dehydrated context to every queue
            // payload, which is how one correlation id follows work
            // across processes.
            $dehydrated = Context::dehydrate();
        };

        $this->runner()->run(new TwoStepWorkflow, [], new ExecutionOptions('req-abc123'));

        $this->assertIsArray($dehydrated);

        // The run is over and the context is clean, so rehydrating the
        // payload is exactly what a worker on another process does.
        $this->assertEmpty($this->laraflowKeys());
        Context::hydrate($dehydrated);

        $this->assertSame('req-abc123', Context::get('laraflow.correlation_id'));
        $this->assertSame(StepOne::class, Context::get('laraflow.step'));
        $this->assertSame(1, Context::get('laraflow.execution_id'));
    }

    public function test_the_feature_can_be_switched_off(): void
    {
        config(['laraflow.logging.context' => false]);
        $this->app->forgetInstance(LoggingContext::class);
        $this->app->forgetInstance(WorkflowRunner::class);

        $seen = [];
        StepOne::$beforeReturn = function () use (&$seen): void {
            $seen = Context::all();
        };

        $this->runner()->run(new TwoStepWorkflow);

        $this->assertSame([], $seen);
    }

    public function test_it_degrades_to_a_no_op_without_a_context_repository(): void
    {
        // A non-Laravel container resolving the runner must still work:
        // the context repository is the framework's, not the package's.
        $logging = new LoggingContext(null);

        // Entering and leaving without a repository must not throw.
        $this->expectNotToPerformAssertions();

        $logging->enter($this->runner()->start(new TwoStepWorkflow));
        $logging->leave();
    }

    /**
     * @return array<string, mixed>
     */
    protected function laraflowKeys(): array
    {
        return array_filter(
            Context::all(),
            fn (string $key): bool => str_starts_with($key, LoggingContext::PREFIX),
            ARRAY_FILTER_USE_KEY,
        );
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
