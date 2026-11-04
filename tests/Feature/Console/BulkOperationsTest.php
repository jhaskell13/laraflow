<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\FixedClock;
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
use Psr\Clock\ClockInterface;
use ReflectionMethod;
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
        $this->assertStringContainsString('Dry run over 2 of 2 matching execution(s)', $output);
        $this->assertStringContainsString('2 would be cancelled', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame(3, WorkflowExecutionModel::query()->where('status', 'pending')->count());
    }

    public function test_bulk_cancel_acts_oldest_first_up_to_the_limit_and_reports_each_row(): void
    {
        $first = $this->pending('acme');
        $second = $this->pending('acme');
        $third = $this->pending('acme');
        $fourth = $this->pending('acme');
        $other = $this->pending('globex');

        // One is already done: never selected, so it neither counts
        // toward the limit nor stands in front of the next run.
        $this->app->make(WorkflowRunner::class)->execute($second, new OneStepWorkflow);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', [
            '--tenant' => 'acme',
            '--limit' => '2',
            '--reason' => 'outage cleanup',
            '--force' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Handled 2 of 3 matching execution(s): 2 cancelled', $output);
        $this->assertStringContainsString('run the same command again to continue', $output);

        $this->assertTrue($this->repository()->find($first)->isCancelled(), 'Oldest first.');
        $this->assertSame('outage cleanup', $this->repository()->find($first)->cancelReason);
        $this->assertTrue($this->repository()->find($second)->isCompleted(), 'Completed is not selected at all.');
        $this->assertTrue($this->repository()->find($third)->isCancelled(), 'The completed row did not use up a slot.');
        $this->assertTrue($this->repository()->find($fourth)->isPending(), 'Past the limit: untouched, for the next run.');
        $this->assertTrue($this->repository()->find($other)->isPending(), 'Another tenant: not in the filter.');

        // ...and the next run makes progress instead of meeting the
        // same rows again.
        ['output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--force' => true]);
        $this->assertStringContainsString('Handled 1 of 1 matching execution(s): 1 cancelled', $output);
        $this->assertTrue($this->repository()->find($fourth)->isCancelled());
    }

    public function test_bulk_cancel_does_not_select_what_is_already_cancelled(): void
    {
        $id = $this->pending('acme');
        $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--force' => true]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--force' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('No cancellable executions match that filter', $output);
        $this->assertTrue($this->repository()->find($id)->isCancelled());
    }

    public function test_a_status_filter_naming_only_terminal_statuses_is_refused_by_name(): void
    {
        $this->pending('acme');

        foreach ([['laraflow:cancel', 'cancel'], ['laraflow:execute', 're-run']] as [$command, $verb]) {
            ['status' => $status, 'output' => $output] = $this->callCommand($command, ['--status' => ['completed', 'cancelled'], '--force' => true]);

            $this->assertSame(CommandStatus::FAILURE, $status, $command);
            $this->assertStringContainsString("A bulk {$verb} cannot act on completed or cancelled executions", $output);
        }

        // A mixed list keeps the actionable half and says what it ignored.
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--status' => ['pending,completed'], '--dry-run' => true, '--json' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['completed'], $document['ignored_statuses']);
        $this->assertSame(1, $document['matched']);
    }

    public function test_dry_runs_predict_each_outcome_by_status_in_snake_case(): void
    {
        $clock = new FixedClock('2026-11-03 12:00:00');
        $this->app->instance(ClockInterface::class, $clock);

        $pending = $this->pending('acme');
        $live = $this->pending('acme');
        $notDue = $this->pending('acme');

        // A live claim, and a retry scheduled an hour out.
        WorkflowExecutionModel::query()->whereKey($live)->update(['status' => 'running', 'claimed_at' => '2026-11-03 11:59:00', 'claim_token' => 'x']);
        WorkflowExecutionModel::query()->whereKey($notDue)->update(['status' => 'retrying', 'next_retry_at' => '2026-11-03 13:00:00']);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--dry-run' => true, '--json' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $cancel = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $outcomes = array_column($cancel['actions'], 'outcome', 'execution');
        $this->assertSame('would_cancel', $outcomes[$pending]);
        $this->assertSame('would_request', $outcomes[$live]);
        $this->assertSame('would_cancel', $outcomes[$notDue]);
        $this->assertSame(['would_cancel' => 2, 'would_request' => 1, 'already_cancelled' => 0, 'would_refuse' => 0], $cancel['counts']);

        ['output' => $output] = $this->callCommand('laraflow:execute', ['--tenant' => 'acme', '--dry-run' => true, '--json' => true]);
        $execute = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $outcomes = array_column($execute['actions'], 'outcome', 'execution');
        $this->assertSame('would_run', $outcomes[$pending]);
        $this->assertSame('would_refuse', $outcomes[$live]);
        $this->assertSame('would_refuse', $outcomes[$notDue]);
        $this->assertStringContainsString('--now', (string) array_column($execute['actions'], 'message', 'execution')[$notDue]);

        // --now overrides the schedule, not a live claim.
        ['output' => $output] = $this->callCommand('laraflow:execute', ['--tenant' => 'acme', '--now' => true, '--dry-run' => true, '--json' => true]);
        $outcomes = array_column(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['actions'], 'outcome', 'execution');
        $this->assertSame('would_run', $outcomes[$notDue]);
        $this->assertSame('would_refuse', $outcomes[$live]);

        ['output' => $output] = $this->callCommand('laraflow:execute', ['--tenant' => 'acme', '--queue' => true, '--dry-run' => true, '--json' => true]);
        $outcomes = array_column(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['actions'], 'outcome', 'execution');
        $this->assertSame('would_queue', $outcomes[$pending]);

        // already_cancelled is predicted for a row cancelled after
        // selection; the selection itself never offers one.
        $command = $this->app->make(\Laraflow\Console\CancelCommand::class);
        $predict = new ReflectionMethod($command, 'predict');
        $cancelled = $this->app->make(WorkflowRunner::class)->cancel($pending);
        $this->assertSame('already_cancelled', $predict->invoke($command, $cancelled)['outcome']);
    }

    public function test_a_refused_row_is_named_instead_of_promising_progress_on_the_next_run(): void
    {
        $live = $this->pending('acme');
        $this->pending('acme');
        WorkflowExecutionModel::query()->whereKey($live)->update(['status' => 'running', 'claimed_at' => now(), 'claim_token' => 'x']);

        ['output' => $output] = $this->callCommand('laraflow:execute', ['--tenant' => 'acme', '--limit' => '1', '--force' => true]);

        $this->assertStringContainsString('1 refused', $output);
        $this->assertStringContainsString('would be selected first again', $output);
        $this->assertStringNotContainsString('run the same command again', $output);
    }

    public function test_a_cancellation_honoured_mid_run_is_its_own_bucket_not_ran(): void
    {
        $execute = new ReflectionMethod(\Laraflow\Console\ExecuteCommand::class, 'bucket');
        $command = $this->app->make(\Laraflow\Console\ExecuteCommand::class);

        $this->assertSame('ran', $execute->invoke($command, 'completed'));
        $this->assertSame('ran', $execute->invoke($command, 'retrying'));
        $this->assertSame('cancelled', $execute->invoke($command, 'cancelled'));
        $this->assertSame('failed', $execute->invoke($command, 'failed'));
        $this->assertSame('failed', $execute->invoke($command, 'running'));
    }

    public function test_matched_is_never_smaller_than_selected(): void
    {
        $this->pending('acme');
        $this->pending('acme');

        ['output' => $output] = $this->callCommand('laraflow:execute', ['--tenant' => 'acme', '--dry-run' => true, '--json' => true]);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $document['matched']);
        $this->assertSame(2, $document['selected']);
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
        $this->assertStringContainsString('Execution completed', $output, 'Each row says what it came to.');

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

    public function test_json_bulk_runs_require_force_exactly_where_the_human_form_would_prompt(): void
    {
        $this->pending('acme');

        // Production is where the human form prompts; a machine cannot
        // answer, so --json without --force is refused there.
        $this->app['env'] = 'production';

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--json' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($document['ok']);
        $this->assertStringContainsString('--force', $document['error']);
        $this->assertSame(1, WorkflowExecutionModel::query()->where('status', 'pending')->count());

        // Outside production neither form asks, and the exit codes stay
        // identical: no --force needed.
        $this->app['env'] = 'testing';

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:cancel', ['--tenant' => 'acme', '--json' => true]);

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
