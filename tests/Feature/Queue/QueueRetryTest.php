<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Queue;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowRunner;

/**
 * The queue fast path for scheduled retries, proven like
 * QueueRecoveryTest proves recovery: against a real database queue
 * driver and a real queue:work worker, so delayed availability and
 * payload round-tripping are exercised rather than assumed.
 */
class QueueRetryTest extends TestCase
{
    use RunsArtisanCommands;

    protected function setUp(): void
    {
        parent::setUp();

        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
    }

    public function test_a_scheduled_retry_is_delivered_by_the_queue_and_completes(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 0, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $this->app->make(WorkflowDispatcher::class)->dispatch(new PolicyWorkflow);

        $this->workQueue();

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Completed->value, $model->status);
        $this->assertSame(1, StepOne::$runs, 'The checkpointed step never re-runs.');
        $this->assertSame(2, StepTwo::$runs, 'The failing step ran once per attempt.');

        // Neither the retryable failure nor its redelivery is a failed
        // job: failed_jobs stays reserved for terminal problems.
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_the_delayed_delivery_carries_the_backoff_and_is_not_run_early(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 120, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $this->app->make(WorkflowDispatcher::class)->dispatch(new PolicyWorkflow);

        $before = time();
        $this->workQueue();

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Retrying->value, $model->status);
        $this->assertSame(1, StepTwo::$runs, 'The worker must not run a retry before it is due.');

        // One delayed delivery waits, available at the scheduled time.
        // Read as vars: the builder types sole() as plain `object` on
        // the oldest supported dependency set.
        $job = get_object_vars(DB::table('jobs')->sole());
        $this->assertIsNumeric($job['available_at'] ?? null);
        $this->assertEqualsWithDelta($before + 120, (int) $job['available_at'], 15);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_the_sync_driver_leaves_the_retry_to_the_recovery_scan(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 60, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->start(new PolicyWorkflow);

        // The application default is sync: delay() would be ignored and
        // the retry would recurse immediately, burning the budget with
        // no backoff. The guard must leave the schedule alone instead.
        config(['queue.default' => 'sync']);

        $this->app->call([new ExecuteWorkflowJob($execution->id), 'handle']);

        $this->assertSame(1, StepTwo::$runs, 'No recursive immediate retry on the sync driver.');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(
            ExecutionStatus::Retrying->value,
            WorkflowExecutionModel::query()->sole()->status,
            'The schedule stands; laraflow:recover delivers it.',
        );
    }

    public function test_an_early_duplicate_delivery_is_a_no_op_not_a_failure(): void
    {
        PolicyWorkflow::$policy = new StepPolicy(
            maxAttempts: 3,
            baseDelaySeconds: 3600,
            maxDelaySeconds: 3600,
            jitter: 0.0,
        );
        StepTwo::$failTimes = 1;

        $execution = $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);
        $this->assertTrue($execution->isRetrying());

        // A duplicate arrives an hour early. The claim's schedule gate
        // refuses it and the job treats that as nothing to do.
        $this->app->call([new ExecuteWorkflowJob($execution->id), 'handle']);

        $this->assertSame(1, StepTwo::$runs);
        $this->assertSame(
            ExecutionStatus::Retrying->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }

    public function test_disabling_the_fast_path_queues_nothing(): void
    {
        config(['laraflow.retry.dispatch_delayed' => false]);

        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 30, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $this->app->make(WorkflowDispatcher::class)->dispatch(new PolicyWorkflow);

        $this->workQueue();

        $this->assertSame(
            ExecutionStatus::Retrying->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
        $this->assertSame(0, DB::table('jobs')->count(), 'The scan, not the queue, owns delivery.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_laraflow_recover_delivers_a_due_retry_without_the_fast_path(): void
    {
        // The contractual delivery promise: the fast path is off, so no
        // delayed job exists anywhere — only the durable schedule. The
        // scan must honour it, and only once it is due.
        config(['laraflow.retry.dispatch_delayed' => false]);

        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 60, jitter: 0.0);
        StepTwo::$failTimes = 1;

        Date::setTestNow('2026-10-23 12:00:00');

        $execution = $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);
        $this->assertTrue($execution->isRetrying());

        // Thirty seconds early: the schedule is respected.
        Date::setTestNow('2026-10-23 12:00:30');
        ['output' => $output] = $this->callCommand('laraflow:recover');
        $this->assertStringContainsString('Nothing to recover', $output);

        // Once due, the scan reports it as a due retry and runs it.
        Date::setTestNow('2026-10-23 12:01:00');
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--sync' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('retry due', $output);
        $this->assertStringContainsString('1 ran', $output);
        $this->assertSame(2, StepTwo::$runs);
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }

    public function test_a_terminal_failure_on_the_worker_still_reaches_failed_jobs(): void
    {
        // No policy: the Milestone 2 queue contract is untouched by the
        // fast path — a terminal failure propagates and Laravel records it.
        StepTwo::$failTimes = 1;

        $this->app->make(WorkflowDispatcher::class)->dispatch(new PolicyWorkflow);

        $this->workQueue();

        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
    }

    /**
     * Drain the queue with a real worker, bounded so a bug cannot hang
     * the suite.
     */
    protected function workQueue(int $maxJobs = 10): void
    {
        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-jobs' => $maxJobs,
            '--max-time' => 15,
            '--sleep' => 0,
        ]);
    }
}
