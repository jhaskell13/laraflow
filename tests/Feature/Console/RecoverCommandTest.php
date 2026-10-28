<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\MutableWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Symfony\Component\Console\Command\Command as CommandStatus;

class RecoverCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_it_reports_when_there_is_nothing_to_recover(): void
    {
        Queue::fake();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Nothing to recover', $output);
        Queue::assertNothingPushed();
    }

    public function test_it_redispatches_a_stale_running_execution(): void
    {
        Queue::fake();

        $execution = $this->makeStaleExecution();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('stale claim', $output);
        $this->assertStringContainsString('dispatched', $output);
        $this->assertStringContainsString('1 dispatched', $output);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $execution->id,
        );
    }

    public function test_a_fresh_claim_is_left_alone(): void
    {
        Queue::fake();

        Date::setTestNow('2026-10-22 10:00:00');

        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);
        $this->app->make(ExecutionRepository::class)->claim($execution->id, 'live-worker', 300);

        Date::setTestNow('2026-10-22 10:02:00');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Nothing to recover', $output);
        Queue::assertNothingPushed();
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        Queue::fake();

        $execution = $this->makeStaleExecution();
        $before = WorkflowExecutionModel::query()->whereKey($execution->id)->sole()->toArray();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--dry-run' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('would dispatch', $output);
        $this->assertStringContainsString('Nothing was changed', $output);

        Queue::assertNothingPushed();
        $this->assertSame(
            $before,
            WorkflowExecutionModel::query()->whereKey($execution->id)->sole()->toArray(),
        );
    }

    public function test_an_execution_at_the_attempt_bound_is_abandoned_and_fails_the_run(): void
    {
        Queue::fake();

        $execution = $this->makeStaleExecution();

        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->where('position', 0)
            ->update(['attempts' => 3]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('abandoned (reached 3 step attempts)', $output);
        $this->assertStringContainsString('laraflow:execute', $output);
        Queue::assertNothingPushed();
    }

    public function test_a_fault_before_the_first_step_is_abandoned_once_it_reaches_the_bound(): void
    {
        // The plan was persisted with two steps; a deploy then changed
        // the workflow to one. Every execution of it now claims, fails
        // the definition check, and never writes a step row — so the
        // step attempt counter stays at zero forever.
        Date::setTestNow('2026-10-22 10:00:00');
        MutableWorkflow::$steps = [StepOne::class, StepTwo::class];
        $execution = $this->app->make(WorkflowRunner::class)->start(new MutableWorkflow);
        MutableWorkflow::$steps = [StepOne::class];

        try {
            $this->app->make(WorkflowRunner::class)->execute($execution->id, new MutableWorkflow);
            $this->fail('Expected the definition mismatch.');
        } catch (WorkflowDefinitionMismatchException) {
            // The first worker faulted after claiming. Claim 1.
        }

        // Each recovery run finds the claim stale, runs it inline, and
        // hits the same fault: claims 2 and 3, still no step attempt.
        foreach ([2, 3] as $claim) {
            Date::setTestNow("2026-10-22 1{$claim}:00:00");

            ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--sync' => true]);

            $this->assertSame(CommandStatus::FAILURE, $status, "Run {$claim} must surface the fault.");
            $this->assertStringContainsString('failed: ', $output);
            $this->assertStringContainsString('does not match', $output);
            $this->assertSame($claim, WorkflowExecutionModel::query()->sole()->fruitless_claims);
        }

        // At the bound, recovery stops re-dispatching and says why.
        Date::setTestNow('2026-10-22 14:00:00');
        $before = WorkflowExecutionModel::query()->sole();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--sync' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('abandoned (was claimed 3 times without reaching a step)', $output);
        $this->assertStringContainsString('laraflow:execute', $output);

        $after = WorkflowExecutionModel::query()->sole();
        $this->assertSame(3, $after->fruitless_claims, 'An abandoned execution is not claimed again.');
        $this->assertSame($before->claim_token, $after->claim_token);
        $this->assertSame(0, StepExecutionModel::query()->max('attempts'));
    }

    public function test_an_execution_with_a_deleted_workflow_class_is_never_requeued(): void
    {
        Queue::fake();

        $execution = $this->makeStaleExecution();

        WorkflowExecutionModel::query()->whereKey($execution->id)->update([
            'workflow' => 'App\\Workflows\\DeletedWorkflow',
        ]);

        // However many times the scheduler fires, nothing is queued: the
        // retry would fail before any step runs, so the attempt bound
        // could never stop it — this bound has to.
        foreach ([1, 2, 3] as $run) {
            ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

            $this->assertSame(CommandStatus::FAILURE, $status, "Run {$run} must surface the problem.");
            $this->assertStringContainsString('unrecoverable (workflow class missing)', $output);
            $this->assertStringContainsString('Restore or redeploy the class', $output);
            Queue::assertNothingPushed();
        }
    }

    public function test_the_attempt_bound_can_be_raised_per_run(): void
    {
        Queue::fake();

        $execution = $this->makeStaleExecution();

        StepExecutionModel::query()
            ->where('workflow_execution_id', $execution->id)
            ->where('position', 0)
            ->update(['attempts' => 3]);

        ['status' => $status] = $this->callCommand('laraflow:recover', ['--abandon-after' => '5']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        Queue::assertPushed(ExecuteWorkflowJob::class);
    }

    public function test_sync_mode_runs_the_execution_in_process(): void
    {
        Queue::fake();

        $execution = $this->makeStaleExecution();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--sync' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('completed', $output);
        $this->assertStringContainsString('1 ran', $output);

        Queue::assertNothingPushed();
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->whereKey($execution->id)->sole()->status,
        );
    }

    public function test_a_sync_failure_is_reported_and_does_not_abort_the_batch(): void
    {
        Queue::fake();

        Date::setTestNow('2026-10-22 10:00:00');

        $failing = $this->makeStaleExecution(freezeTime: false);
        $healthy = $this->makeStaleExecution(freezeTime: false);

        Date::setTestNow('2026-10-22 10:30:00');

        // The failing execution's StepTwo throws once; the healthy one,
        // processed second, must still be recovered.
        StepTwo::$failTimes = 1;

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--sync' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('failed: step two blew up', $output);
        $this->assertStringContainsString('1 ran', $output);
        $this->assertStringContainsString('1 failed', $output);

        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->whereKey($failing->id)->sole()->status,
        );
        $this->assertSame(
            ExecutionStatus::Completed->value,
            WorkflowExecutionModel::query()->whereKey($healthy->id)->sole()->status,
        );
    }

    public function test_a_blank_pending_after_config_does_not_enable_the_scan(): void
    {
        Queue::fake();

        // '' is what a stray empty env line becomes; cast blindly it is
        // 0, which would switch the pending scan on at zero seconds —
        // the one value the option validator refuses deliberately.
        config(['laraflow.recovery.pending_after' => '']);

        Date::setTestNow('2026-10-22 10:00:00');
        $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);
        Date::setTestNow('2026-10-22 11:00:00');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Nothing to recover', $output);
        Queue::assertNothingPushed();
    }

    public function test_pending_executions_are_not_recovered_unless_asked(): void
    {
        Queue::fake();

        Date::setTestNow('2026-10-22 10:00:00');
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);
        Date::setTestNow('2026-10-22 11:00:00');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover');

        $this->assertStringContainsString('Nothing to recover', $output);
        Queue::assertNothingPushed();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--pending-after' => '300']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('never dispatched', $output);

        Queue::assertPushed(
            ExecuteWorkflowJob::class,
            fn (ExecuteWorkflowJob $job): bool => $job->executionId === $execution->id,
        );
    }

    public function test_the_limit_bounds_one_run(): void
    {
        Queue::fake();

        Date::setTestNow('2026-10-22 10:00:00');
        $this->makeStaleExecution(freezeTime: false);
        $this->makeStaleExecution(freezeTime: false);
        $this->makeStaleExecution(freezeTime: false);
        Date::setTestNow('2026-10-22 10:30:00');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:recover', ['--limit' => '2']);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('2 dispatched', $output);
        Queue::assertPushed(ExecuteWorkflowJob::class, 2);
    }

    public function test_the_workflow_filter_narrows_the_scan(): void
    {
        Queue::fake();

        $this->makeStaleExecution();

        ['status' => $status, 'output' => $output] = $this->callCommand(
            'laraflow:recover',
            ['--workflow' => 'App\\Workflows\\SomethingElse'],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Nothing to recover', $output);
        Queue::assertNothingPushed();

        ['status' => $status] = $this->callCommand(
            'laraflow:recover',
            ['--workflow' => TwoStepWorkflow::class],
        );

        $this->assertSame(CommandStatus::SUCCESS, $status);
        Queue::assertPushed(ExecuteWorkflowJob::class, 1);
    }

    public function test_invalid_numeric_options_are_rejected(): void
    {
        Queue::fake();

        foreach (['--stale-after', '--limit', '--abandon-after', '--pending-after'] as $option) {
            ['status' => $status, 'output' => $output] = $this->callCommand(
                'laraflow:recover',
                [$option => '0'],
            );

            $this->assertSame(CommandStatus::FAILURE, $status, "{$option}=0 must be rejected.");
            $this->assertStringContainsString('must be a positive integer', $output);
        }

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * An execution whose worker died mid-step: claimed at 10:00, step one
     * attempted once, clock advanced to 10:30 so the claim is stale.
     */
    protected function makeStaleExecution(bool $freezeTime = true): WorkflowExecution
    {
        if ($freezeTime) {
            Date::setTestNow('2026-10-22 10:00:00');
        }

        $repository = $this->app->make(ExecutionRepository::class);
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        $claimed = $repository->claim($execution->id, bin2hex(random_bytes(16)), 300);

        $steps = $repository->steps($execution);
        $steps[0]->markRunning(Date::now()->toImmutable());
        $repository->saveStep($claimed, $steps[0]);

        if ($freezeTime) {
            Date::setTestNow('2026-10-22 10:30:00');
        }

        return $execution;
    }
}
