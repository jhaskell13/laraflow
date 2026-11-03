<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\OneStepWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * Bulk cancel and re-run by filter: the morning after an outage. A
 * filter is required, --dry-run names and changes nothing, each row is
 * its own attempt, and nothing about the single-execution forms moved.
 */
class BulkOperationsTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_a_bulk_operation_refuses_to_act_on_everything(): void
    {
        $this->pending('acme');

        foreach (['laraflow:cancel', 'laraflow:execute'] as $command) {
            ['status' => $status, 'output' => $output] = $this->callCommand($command, ['--force' => true]);

            $this->assertSame(CommandStatus::FAILURE, $status, $command);
            $this->assertStringContainsString('needs at least one filter', $output);
            $this->assertStringContainsString('no form of this command that acts on everything', $output);
        }

        $this->assertSame(1, WorkflowExecutionModel::query()->where('status', 'pending')->count(), 'Nothing was touched.');
    }

    public function test_an_id_and_a_filter_together_are_refused_as_two_different_instructions(): void
    {
        $id = $this->pending('acme');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['execution' => (string) $id, '--tenant' => 'acme']);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('either an execution id or a filter, not both', $output);
        $this->assertTrue($this->repository()->find($id)->isPending());
    }

    public function test_dry_run_names_what_would_be_cancelled_and_cancels_nothing(): void
    {
        $this->pending('acme');
        $this->pending('acme');
        $this->pending('globex');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--dry-run' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('would cancel', $output);
        $this->assertStringContainsString('2 of 2 matching execution(s) would be cancelled', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame(3, WorkflowExecutionModel::query()->where('status', 'pending')->count());
    }

    public function test_bulk_cancel_acts_oldest_first_up_to_the_limit_and_reports_each_row(): void
    {
        $first = $this->pending('acme');
        $second = $this->pending('acme');
        $third = $this->pending('acme');
        $other = $this->pending('globex');

        // One of the targets is already done: refused per row, not fatal.
        $this->app->make(WorkflowRunner::class)->execute($second, new OneStepWorkflow);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', [
            '--tenant' => 'acme',
            '--limit' => '2',
            '--reason' => 'outage cleanup',
            '--force' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status, 'A refusal is not a failure of the run.');
        $this->assertStringContainsString('Handled 2 of 3 matching execution(s): 1 cancelled', $output);
        $this->assertStringContainsString('1 refused', $output);
        $this->assertStringContainsString('More matching executions exist', $output);

        $this->assertTrue($this->repository()->find($first)->isCancelled(), 'Oldest first.');
        $this->assertSame('outage cleanup', $this->repository()->find($first)->cancelReason);
        $this->assertTrue($this->repository()->find($second)->isCompleted(), 'Completed is not cancellable; it was refused, not failed.');
        $this->assertTrue($this->repository()->find($third)->isPending(), 'Past the limit: untouched, for the next run.');
        $this->assertTrue($this->repository()->find($other)->isPending(), 'Another tenant: not in the filter.');
    }

    public function test_bulk_cancel_is_idempotent_about_what_is_already_cancelled(): void
    {
        $id = $this->pending('acme');
        $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--force' => true]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--force' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('already cancelled', $output);
        $this->assertStringContainsString('1 skipped', $output);
        $this->assertTrue($this->repository()->find($id)->isCancelled());
    }

    public function test_bulk_re_run_recovers_every_failed_execution_a_filter_matches_each_on_its_own(): void
    {
        StepTwo::$failTimes = 10;

        $failed = [];

        foreach ([1, 2, 3] as $i) {
            $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow, [], new ExecutionOptions(tenantId: 'acme'));

            try {
                $this->app->make(WorkflowRunner::class)->execute($execution->id, new TwoStepWorkflow);
            } catch (RuntimeException) {
            }

            $failed[] = $execution->id;
        }

        $this->assertCount(3, $this->repository()->list(new \Laraflow\Workflow\ExecutionFilter(status: ExecutionStatus::Failed), 10)->executions);

        // The fix is deployed: the step works now.
        StepTwo::$failTimes = 0;

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['--status' => ['failed'], '--tenant' => 'acme', '--force' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Handled 3 of 3 matching execution(s): 3 ran', $output);

        foreach ($failed as $id) {
            $this->assertTrue($this->repository()->find($id)->isCompleted());
        }
    }

    public function test_a_row_that_fails_again_does_not_stop_the_rest_and_exits_non_zero(): void
    {
        StepTwo::$failTimes = 100;

        $ids = [];

        foreach ([1, 2] as $i) {
            $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow, [], new ExecutionOptions(tenantId: 'acme'));

            try {
                $this->app->make(WorkflowRunner::class)->execute($execution->id, new TwoStepWorkflow);
            } catch (RuntimeException) {
            }

            $ids[] = $execution->id;
        }

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['--status' => ['failed'], '--force' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('2 failed', $output);
        $this->assertStringContainsString('step two blew up', $output);
        $this->assertStringContainsString('can be recovered again', $output);
        $this->assertSame(4, StepTwo::$runs, 'Both rows were attempted: one failure never stops the batch.');
    }

    public function test_bulk_re_run_can_hand_everything_to_the_queue_and_dry_run_names_it(): void
    {
        config()->set('queue.default', 'database');

        $this->pending('acme');
        $this->pending('acme');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['--tenant' => 'acme', '--queue' => true, '--dry-run' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('would queue', $output);
        $this->assertSame(0, $this->app->make('db')->table('jobs')->count());

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['--tenant' => 'acme', '--queue' => true, '--force' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('2 queued', $output);
        $this->assertSame(2, $this->app->make('db')->table('jobs')->count());
    }

    public function test_json_bulk_runs_require_force_or_dry_run_because_nobody_can_answer_a_prompt(): void
    {
        $this->pending('acme');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--json' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($document['ok']);
        $this->assertStringContainsString('--force', $document['error']);
        $this->assertSame(1, WorkflowExecutionModel::query()->where('status', 'pending')->count());

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--json' => true, '--force' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($document['ok']);
        $this->assertFalse($document['dry_run']);
        $this->assertSame(1, $document['matched']);
        $this->assertSame('cancelled', $document['actions'][0]['outcome']);
        $this->assertSame(1, $document['counts']['cancelled']);
    }

    public function test_the_single_execution_forms_are_unchanged(): void
    {
        $id = $this->pending('acme');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['execution' => (string) $id]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString("Execution [{$id}] cancelled.", $output);

        $other = $this->pending('acme');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['execution' => (string) $other]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Completed steps will be skipped', $output);
        $this->assertStringContainsString('Execution completed', $output);
    }

    protected function pending(string $tenant): int
    {
        return $this->app->make(WorkflowRunner::class)->start(new OneStepWorkflow, [], new ExecutionOptions(tenantId: $tenant))->id;
    }

    protected function repository(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
