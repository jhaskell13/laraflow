<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Queue;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\CrashingExecutionRepository;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\SimulatedWorkerCrash;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\StepStatus;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * End-to-end coverage of the Milestone 2 exit criteria, deliberately
 * without Queue::fake(): jobs are serialised into a real `jobs` table and
 * executed by a real `queue:work` worker, so payload round-tripping and
 * worker behaviour are exercised rather than assumed.
 */
class QueueRecoveryTest extends TestCase
{
    use RunsArtisanCommands;

    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new ArrayLogger;
    }

    // ------------------------------------------------------------------
    // Exit criterion: start asynchronously, inspect, complete
    // ------------------------------------------------------------------

    public function test_an_execution_dispatched_to_the_queue_is_run_by_a_worker(): void
    {
        $execution = $this->app->make(WorkflowDispatcher::class)
            ->dispatch(new TwoStepWorkflow, ['customer_id' => 42, 'secret' => 'tok_SENTINEL']);

        // Nothing has run yet, and the execution is inspectable.
        $this->assertTrue($execution->isPending());
        $this->assertSame(0, StepOne::$runs);
        $this->assertSame(1, DB::table('jobs')->count());

        // The real queue payload carries the id, never the input.
        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('tok_SENTINEL', $payload);
        $this->assertStringContainsString('ExecuteWorkflowJob', $payload);

        $this->workQueue();

        $model = WorkflowExecutionModel::query()->sole();

        $this->assertSame(ExecutionStatus::Completed->value, $model->status);
        $this->assertSame('from-step-one', $model->context['one'] ?? null);
        $this->assertSame('from-step-two', $model->context['two'] ?? null);
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    // ------------------------------------------------------------------
    // Exit criterion: recover work after worker interruption
    // ------------------------------------------------------------------

    public function test_a_worker_that_dies_mid_step_is_recovered_from_its_last_checkpoint(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $execution = $this->app->make(WorkflowDispatcher::class)->dispatch(new TwoStepWorkflow);

        // The worker dies after step one's checkpoint commits, while step
        // two is mid-flight: its side effect happened but never committed.
        $this->crashWhileRunning($execution, dieOn: 'checkpoint', call: 2);

        $steps = StepExecutionModel::query()->orderBy('position')->get();
        $this->assertSame(StepStatus::Completed->value, $this->row($steps, 0, 'step')->status);
        $this->assertSame(StepStatus::Running->value, $this->row($steps, 1, 'step')->status);
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Running->value, $model->status);
        $this->assertNotNull($model->claim_token, 'A dead worker leaves its claim behind.');

        // Recovery does nothing while the claim still looks alive.
        Date::setTestNow('2026-10-22 10:02:00');
        ['output' => $output] = $this->callCommand('laraflow:recover');
        $this->assertStringContainsString('Nothing to recover', $output);
        $this->assertSame(0, DB::table('jobs')->count());

        // Once the claim ages out, recovery re-dispatches it.
        Date::setTestNow('2026-10-22 10:30:00');
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('stale claim', $output);
        $this->assertStringContainsString('dispatched', $output);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->workQueue();

        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Completed->value, $model->status);
        $this->assertNull($model->claim_token, 'A completed execution releases its claim.');

        // The checkpointed step was skipped; the interrupted step re-ran.
        // That re-run is the documented at-least-once boundary.
        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(2, StepTwo::$runs);

        $steps = StepExecutionModel::query()->orderBy('position')->get();
        $this->assertSame(1, $this->row($steps, 0, 'step')->attempts);
        $this->assertSame(2, $this->row($steps, 1, 'step')->attempts);
        $this->assertSame('from-step-two', $model->context['two'] ?? null);
    }

    public function test_recovery_can_also_run_interrupted_work_in_process(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $execution = $this->app->make(WorkflowDispatcher::class)->dispatch(new TwoStepWorkflow);
        $this->crashWhileRunning($execution, dieOn: 'checkpoint', call: 1);

        Date::setTestNow('2026-10-22 10:30:00');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--sync' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('1 ran', $output);

        // Nothing was queued: --sync ran it here.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );

        // Step one crashed before its checkpoint, so it ran again.
        $this->assertSame(2, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);
    }

    // ------------------------------------------------------------------
    // Exit criterion: duplicate deliveries are safe
    // ------------------------------------------------------------------

    public function test_duplicate_deliveries_run_the_workflow_exactly_once(): void
    {
        $dispatcher = $this->app->make(WorkflowDispatcher::class);

        $execution = $dispatcher->dispatch(new TwoStepWorkflow);

        // The same execution delivered three more times, as an at-least-once
        // queue may legitimately do.
        $dispatcher->dispatchExecution($execution->id);
        $dispatcher->dispatchExecution($execution->id);
        $dispatcher->dispatchExecution($execution->id);

        $this->assertSame(4, DB::table('jobs')->count());

        $this->workQueue();

        $this->assertSame(1, StepOne::$runs);
        $this->assertSame(1, StepTwo::$runs);
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );

        // Duplicates are a no-op, not a failure.
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    // ------------------------------------------------------------------
    // Exit criterion: bounded recovery, no infinite retry loop
    // ------------------------------------------------------------------

    public function test_a_crash_looping_execution_stops_being_retried(): void
    {
        Date::setTestNow('2026-10-22 10:00:00');

        $execution = $this->app->make(WorkflowDispatcher::class)->dispatch(new TwoStepWorkflow);

        // Three interrupted runs an hour apart, so each one can reclaim the
        // previous worker's stale claim. The attempt bound (3) is reached.
        foreach (['10:00:00', '11:00:00', '12:00:00'] as $time) {
            Date::setTestNow("2026-10-22 {$time}");

            $this->crashWhileRunning($execution, dieOn: 'checkpoint', call: 1);
        }

        $this->assertSame(
            3,
            StepExecutionModel::query()->where('position', 0)->value('attempts'),
        );

        Date::setTestNow('2026-10-22 13:00:00');

        // Every further scheduled run reports the execution and refuses to
        // retry it, however many times the scheduler fires.
        for ($run = 0; $run < 3; $run++) {
            ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

            $this->assertSame(CommandStatus::FAILURE, $status, 'Abandoned work must surface to the scheduler.');
            $this->assertStringContainsString('abandoned (reached 3 step attempts)', $output);
            $this->assertStringContainsString('laraflow:execute', $output);
            $this->assertSame(0, DB::table('jobs')->count(), 'An abandoned execution must never be re-queued.');
        }

        // The operator override still works.
        ['status' => $status] = $this->callCommand('laraflow:execute', ['execution' => (string) $execution->id]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->sole()->status,
        );
    }

    // ------------------------------------------------------------------
    // The two retry systems do not multiply
    // ------------------------------------------------------------------

    public function test_a_failing_step_is_attempted_once_and_recorded_by_both_systems(): void
    {
        StepTwo::$failTimes = 99;

        $this->app->make(WorkflowDispatcher::class)->dispatch(new TwoStepWorkflow);

        $this->workQueue();

        // The queue did not retry: one delivery, one attempt.
        $this->assertSame(1, StepTwo::$runs);
        $this->assertSame(
            1,
            StepExecutionModel::query()->where('position', 1)->value('attempts'),
        );

        // Laraflow persisted the failure, and the queue recorded the job.
        $model = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Failed->value, $model->status);
        $this->assertSame('step two blew up', $model->error);

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

        // laraflow.queue.* keeps its shipped defaults here on purpose,
        // including after_commit: these tests exercise what users get.
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Run the execution with a repository that dies at a precise
     * persistence boundary, exactly as a killed worker would.
     *
     * The queued job is removed first: a worker reserves a job before it
     * runs, so a job whose worker died is no longer waiting to be popped.
     * That also keeps Laravel's own `retry_after` redelivery out of these
     * assertions — Laraflow's stale-claim recovery is the mechanism under
     * test, and it works on every queue driver.
     */
    protected function crashWhileRunning(
        WorkflowExecution $execution,
        string $dieOn,
        int $call,
        string $phase = 'before',
    ): void {
        DB::table('jobs')->delete();

        $runner = new WorkflowRunner(
            repository: new CrashingExecutionRepository(
                $this->app->make(ExecutionRepository::class),
                $dieOn,
                $call,
                $phase,
            ),
            container: $this->app,
            logger: $this->logger,
            options: new RunnerOptions(staleAfterSeconds: 300),
        );

        try {
            $runner->execute($execution->id, new TwoStepWorkflow);

            $this->fail('The simulated worker crash should have propagated.');
        } catch (SimulatedWorkerCrash) {
            // The process is "gone"; durable state is whatever committed.
        }
    }

    /**
     * Drain the queue with a real worker. Bounded so a bug cannot hang the
     * suite.
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
