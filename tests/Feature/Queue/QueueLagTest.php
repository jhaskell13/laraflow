<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Queue;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;

/**
 * Queue lag: how long an execution waited for a worker, measured from
 * when the queue was asked to make it runnable to when a worker claimed
 * it. The distinction between "dispatched" and "available" is the whole
 * point — a retry held back by its own backoff has not been waiting.
 */
class QueueLagTest extends TestCase
{
    use RunsArtisanCommands;

    protected FixedClock $clock;

    /** @var list<WorkflowExecutionStarted> */
    protected array $started = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Bound before any Laraflow service resolves, so the dispatcher,
        // runner, and repository all read this one clock.
        $this->clock = new FixedClock('2026-10-24 09:00:00');
        $this->app->instance(ClockInterface::class, $this->clock);

        $this->started = [];
        Event::listen(WorkflowExecutionStarted::class, function (WorkflowExecutionStarted $event): void {
            $this->started[] = $event;
        });
    }

    // ------------------------------------------------------------------
    // Recording the dispatch
    // ------------------------------------------------------------------

    public function test_an_immediate_dispatch_is_available_the_moment_it_is_dispatched(): void
    {
        Queue::fake();

        $execution = $this->dispatcher()->dispatch(new TwoStepWorkflow);

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertSame('2026-10-24 09:00:00', $row->dispatched_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-24 09:00:00', $row->available_at?->format('Y-m-d H:i:s'));

        // And it is on the handle the application receives back.
        $reloaded = $this->repository()->find($execution->id);
        $this->assertEquals($reloaded->dispatchedAt, $reloaded->availableAt);
    }

    public function test_a_delayed_dispatch_records_when_it_becomes_available(): void
    {
        Queue::fake();

        $execution = $this->runner()->start(new TwoStepWorkflow);

        $this->dispatcher()->dispatchExecution(
            $execution->id,
            availableAt: new DateTimeImmutable('2026-10-24 09:02:00'),
        );

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertSame('2026-10-24 09:00:00', $row->dispatched_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-24 09:02:00', $row->available_at?->format('Y-m-d H:i:s'));
    }

    public function test_an_availability_in_the_past_means_now(): void
    {
        Queue::fake();

        $execution = $this->runner()->start(new TwoStepWorkflow);

        // A caller cannot make work available before it dispatched it.
        $this->dispatcher()->dispatchExecution(
            $execution->id,
            availableAt: new DateTimeImmutable('2026-10-24 08:00:00'),
        );

        $this->assertSame(
            '2026-10-24 09:00:00',
            WorkflowExecutionModel::query()->sole()->available_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_each_dispatch_overwrites_the_last(): void
    {
        Queue::fake();

        $execution = $this->dispatcher()->dispatch(new TwoStepWorkflow);

        $this->clock->advance(600);
        $this->dispatcher()->dispatchExecution($execution->id);

        // The columns describe the most recent delivery — the one whose
        // wait a claim would be measuring.
        $this->assertSame(
            '2026-10-24 09:10:00',
            WorkflowExecutionModel::query()->sole()->dispatched_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_recording_a_dispatch_for_an_unknown_execution_fails_loudly(): void
    {
        $this->expectException(ExecutionNotFoundException::class);

        $this->repository()->recordDispatch(999, $this->clock->now());
    }

    public function test_the_fast_path_retry_is_available_at_its_due_time(): void
    {
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 120, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $execution = $this->runner()->start(new PolicyWorkflow);

        // The job runs in-process and, on the retryable failure, queues
        // the delayed redelivery for next_retry_at.
        config(['queue.default' => 'database']);
        $this->app->call([new ExecuteWorkflowJob($execution->id), 'handle']);

        $row = WorkflowExecutionModel::query()->sole();
        $this->assertNotNull($row->next_retry_at);
        $this->assertSame(
            $row->next_retry_at->format('Y-m-d H:i:s'),
            $row->available_at?->format('Y-m-d H:i:s'),
            'The retry becomes available exactly when it is due, so its backoff is not counted as queue wait.',
        );
        $this->assertSame('2026-10-24 09:00:00', $row->dispatched_at?->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------------------
    // Measuring the wait
    // ------------------------------------------------------------------

    public function test_the_started_event_carries_the_wait_since_availability(): void
    {
        Queue::fake();

        $execution = $this->dispatcher()->dispatch(new TwoStepWorkflow);

        // A worker picks it up 45 seconds later.
        $this->clock->advance(45);
        $this->runner()->execute($execution->id, new TwoStepWorkflow);

        $this->assertCount(1, $this->started);
        $this->assertSame(45, $this->started[0]->queueLagSeconds);
    }

    public function test_a_delayed_retry_counts_only_the_wait_past_its_due_time(): void
    {
        Queue::fake();

        $execution = $this->runner()->start(new TwoStepWorkflow);

        // Due in two minutes; a worker claims it thirty seconds late.
        $this->dispatcher()->dispatchExecution(
            $execution->id,
            availableAt: new DateTimeImmutable('2026-10-24 09:02:00'),
        );
        $this->clock->set('2026-10-24 09:02:30');
        $this->runner()->execute($execution->id, new TwoStepWorkflow);

        $this->assertSame(30, $this->started[0]->queueLagSeconds, 'The 120 s backoff is not queue wait.');
    }

    public function test_an_in_process_run_reports_no_queue_wait(): void
    {
        $this->runner()->run(new TwoStepWorkflow);

        $this->assertCount(1, $this->started);
        $this->assertNull($this->started[0]->queueLagSeconds, 'Nothing was dispatched, so nothing waited.');
    }

    public function test_claiming_ahead_of_availability_reports_no_queue_wait(): void
    {
        Queue::fake();

        $execution = $this->runner()->start(new TwoStepWorkflow);

        $this->dispatcher()->dispatchExecution(
            $execution->id,
            availableAt: new DateTimeImmutable('2026-10-24 09:05:00'),
        );

        // An operator runs it now, before the queue would have.
        $this->runner()->execute($execution->id, new TwoStepWorkflow);

        $this->assertNull(
            $this->started[0]->queueLagSeconds,
            'Running early is not a wait of zero; it is no wait at all.',
        );
    }

    public function test_the_wait_is_visible_on_the_sync_driver_too(): void
    {
        // The sync driver runs the job inside dispatch(); the dispatch
        // must be recorded *before* the push or the claim cannot see it.
        $this->dispatcher()->dispatch(new TwoStepWorkflow);

        $this->assertCount(1, $this->started);
        $this->assertSame(0, $this->started[0]->queueLagSeconds, 'Same instant, so a wait of zero — measured, not absent.');
    }

    public function test_a_recovered_execution_measures_from_its_last_dispatch(): void
    {
        Queue::fake();

        $execution = $this->dispatcher()->dispatch(new TwoStepWorkflow);

        // The dispatch was lost; recovery finds it ten minutes later and
        // re-dispatches, and a worker claims that delivery after 5 s.
        $this->clock->advance(600);
        $this->dispatcher()->dispatchExecution($execution->id);
        $this->clock->advance(5);
        $this->runner()->execute($execution->id, new TwoStepWorkflow);

        $this->assertSame(5, $this->started[0]->queueLagSeconds, 'The wait is for the delivery actually claimed.');
    }

    // ------------------------------------------------------------------
    // Operator view
    // ------------------------------------------------------------------

    public function test_show_reports_dispatch_time_and_the_open_wait_of_a_pending_execution(): void
    {
        Queue::fake();
        Date::setTestNow('2026-10-24 09:00:00');

        $execution = $this->dispatcher()->dispatch(new TwoStepWorkflow);

        Date::setTestNow('2026-10-24 09:01:30');
        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id]);

        $this->assertStringContainsString('Dispatched', $output);
        $this->assertStringContainsString('2026-10-24 09:00:00', $output);
        $this->assertStringContainsString('Queue wait', $output);
        $this->assertStringContainsString('1 min 30 s so far', $output);
        $this->assertStringNotContainsString('Available', $output, 'Shown only when it differs from the dispatch time.');
    }

    public function test_show_names_the_availability_of_a_delayed_retry(): void
    {
        Queue::fake();

        $execution = $this->runner()->start(new TwoStepWorkflow);
        $this->dispatcher()->dispatchExecution(
            $execution->id,
            availableAt: new DateTimeImmutable('2026-10-24 09:02:00'),
        );

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id]);

        $this->assertStringContainsString('Available', $output);
        $this->assertStringContainsString('2026-10-24 09:02:00', $output);
        $this->assertStringContainsString('held back by its retry backoff', $output);
    }

    public function test_show_omits_queue_timing_for_an_in_process_run(): void
    {
        $execution = $this->runner()->run(new TwoStepWorkflow);

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $execution->id]);

        $this->assertStringNotContainsString('Dispatched', $output);
        $this->assertStringNotContainsString('Queue wait', $output);
    }

    protected function dispatcher(): WorkflowDispatcher
    {
        return $this->app->make(WorkflowDispatcher::class);
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }

    protected function repository(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
