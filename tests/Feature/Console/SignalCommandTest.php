<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * laraflow:signal: the console form of the dashboard's signal action
 * and of a trigger request, through the same signal() and the same
 * Handoff.
 */
class SignalCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_a_signal_is_recorded_and_delivery_is_left_to_recovery_by_default(): void
    {
        $id = $this->parked();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', [
            'execution' => (string) $id,
            'signal' => 'manager-approval',
            '--payload' => '{"approved_by": "ops"}',
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('recorded', $output);
        $this->assertStringContainsString('--deliver', $output);

        $execution = $this->repository()->find($id);
        $this->assertTrue($execution->isWaiting(), 'Recorded, not delivered: the execution is due now, and nothing ran it yet.');
        $this->assertNotNull($execution->nextRetryAt);
        $this->assertSame(['approved_by' => 'ops'], $this->repository()->steps($execution)[1]->output);
        $this->assertSame(0, StepTwo::$runs);
    }

    public function test_deliver_hands_the_execution_on_and_the_sync_driver_runs_it(): void
    {
        $id = $this->parked();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', [
            'execution' => (string) $id,
            'signal' => 'manager-approval',
            '--deliver' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('Execution completed', $output);
        $this->assertTrue($this->repository()->find($id)->isCompleted());
        $this->assertSame(1, StepTwo::$runs);
    }

    public function test_refusals_are_the_runtimes_words_and_nothing_changes(): void
    {
        $id = $this->parked();

        // A finished execution can never park on a signal again. (An
        // unfinished one keeps a signal for another name in its inbox.)
        $finished = $this->parked();
        $this->app->make(WorkflowRunner::class)->cancel($finished);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', ['execution' => (string) $finished, 'signal' => 'wrong-signal']);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('wrong-signal', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', ['execution' => (string) $id, 'signal' => 'laraflow.child.1']);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('reserved', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', ['execution' => (string) $id, 'signal' => 'manager-approval', '--payload' => '[1, 2]']);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('must be a JSON object', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', ['execution' => 'nope', 'signal' => 'manager-approval']);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('must be a positive integer execution id', $output);

        $this->assertTrue($this->repository()->find($id)->isWaiting());
    }

    public function test_json_says_what_was_recorded_and_what_became_of_delivery(): void
    {
        $id = $this->parked();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', [
            'execution' => (string) $id,
            'signal' => 'manager-approval',
            '--deliver' => true,
            '--json' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('laraflow:signal', $document['command']);
        $this->assertTrue($document['ok']);
        $this->assertSame('manager-approval', $document['signal']);
        $this->assertSame($id, $document['execution']['id']);
        $this->assertSame('completed', $document['execution']['status']);
        $this->assertSame('ran', $document['handoff']);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', ['execution' => (string) $id, 'signal' => 'manager-approval', '--json' => true]);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($document['ok']);
        $this->assertStringContainsString('not waiting', $document['error']);
        $this->assertSame($id, $document['execution']);
    }

    public function test_a_buffered_signal_says_so_and_deliver_hands_nothing_on(): void
    {
        $id = $this->parked();

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:signal', [
            'execution' => (string) $id,
            'signal' => 'later-approval',
            '--deliver' => true,
            '--json' => true,
        ]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('buffered', $document['recorded']);
        $this->assertNull($document['handoff']);
        $this->assertStringContainsString('not waiting on it yet', $document['message']);
        $this->assertStringNotContainsString('due now', $document['message']);
        $this->assertTrue($this->repository()->find($id)->isWaiting());
        $this->assertSame(0, StepTwo::$runs);

        ['output' => $output] = $this->callCommand('laraflow:signal', ['execution' => (string) $id, 'signal' => 'manager-approval', '--json' => true]);
        $this->assertSame('resumed', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['recorded']);
    }

    protected function parked(): int
    {
        return $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow)->id;
    }

    protected function repository(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
