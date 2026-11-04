<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\NightlyWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * --json on every operating command: one document, one envelope, the
 * same exit code the human form returns, and nothing else on the
 * stream — the contract an automation can build on.
 */
class JsonOutputTest extends TestCase
{
    use RunsArtisanCommands;

    protected FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-11-03 14:30:00');
        $this->app->instance(ClockInterface::class, $this->clock);
    }

    public function test_every_operating_command_declares_json_and_the_generators_and_installer_do_not(): void
    {
        $excluded = ['laraflow:install', 'laraflow:make-workflow', 'laraflow:make-step', 'laraflow:make-connector'];

        foreach (Artisan::all() as $name => $command) {
            // Laraflow's own commands: every laraflow:*, generators
            // included; Laravel's make:* (and the deprecated aliases,
            // which are the generators under another key) are skipped.
            if (! str_starts_with($name, 'laraflow:')) {
                continue;
            }

            if (in_array($name, $excluded, true)) {
                $this->assertFalse($command->getDefinition()->hasOption('json'), "{$name} is scaffolding, not an operation; it has no --json.");

                continue;
            }

            $this->assertTrue($command->getDefinition()->hasOption('json'), "{$name} is missing --json.");
        }
    }

    public function test_every_document_carries_the_envelope_and_the_stream_carries_nothing_else(): void
    {
        $this->callCommand('laraflow:demo');

        foreach ([
            ['laraflow:list', []],
            ['laraflow:show', ['execution' => '1', '--attempts' => true]],
            ['laraflow:summary', []],
            ['laraflow:connectors', []],
            ['laraflow:recover', ['--dry-run' => true]],
            ['laraflow:schedule', ['--dry-run' => true]],
            ['laraflow:schedules', []],
            ['laraflow:prune', ['--prune-attempts-after' => '30', '--dry-run' => true]],
            ['laraflow:children', ['execution' => '1']],
        ] as [$command, $parameters]) {
            ['output' => $output] = $this->callCommand($command, $parameters + ['--json' => true]);

            $document = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

            $this->assertIsArray($document, "{$command} printed something that is not one JSON document.");
            $this->assertSame($command, $document['command'], "{$command} names itself.");
            $this->assertArrayHasKey('ok', $document);
            $this->assertSame(1, substr_count(trim($output), "\n") + 1, "{$command} printed more than one line under --json.");
        }
    }

    public function test_the_exit_code_is_the_human_forms_and_a_refusal_is_an_error_document(): void
    {
        ['status' => $human] = $this->callCommand('laraflow:show', ['execution' => '999']);
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:show', ['execution' => '999', '--json' => true]);

        $this->assertSame(CommandStatus::FAILURE, $human);
        $this->assertSame($human, $status);

        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($document['ok']);
        $this->assertStringContainsString('was not found', $document['error']);
    }

    public function test_show_and_list_serialise_the_record_through_one_shape(): void
    {
        $this->callCommand('laraflow:demo');

        ['output' => $list] = $this->callCommand('laraflow:list', ['--json' => true]);
        ['output' => $show] = $this->callCommand('laraflow:show', ['execution' => '1', '--attempts' => true, '--json' => true]);

        $listed = json_decode($list, true, 512, JSON_THROW_ON_ERROR)['executions'][0];
        $shown = json_decode($show, true, 512, JSON_THROW_ON_ERROR)['execution'];

        $this->assertSame($listed['id'], $shown['id']);
        $this->assertSame('completed', $shown['status']);
        $this->assertSame($listed['created_at'], $shown['created_at'], 'ISO 8601 with offset, the same on both.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $shown['created_at']);

        $this->assertArrayNotHasKey('input', $listed, 'A listing carries no payloads.');
        $this->assertSame(['total' => 3, 'completed' => 3, 'skipped' => 0], $listed['steps']);

        $this->assertIsArray($shown['input'], 'The detail view carries the payloads the human form prints.');
        $this->assertCount(3, $shown['steps']);
        $this->assertSame(0, $shown['steps'][0]['position']);
        $this->assertSame([], $shown['waiting_on']);
        $this->assertNull($shown['started_by']);
        $this->assertCount(3, json_decode($show, true, 512, JSON_THROW_ON_ERROR)['attempts']);
    }

    public function test_a_failed_run_is_a_document_with_the_error_and_a_non_zero_exit(): void
    {
        StepTwo::$failTimes = 10;
        $execution = $this->app->make(WorkflowRunner::class)->start(new TwoStepWorkflow);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['execution' => (string) $execution->id, '--json' => true]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($document['ok']);
        $this->assertSame('failed', $document['outcome']);
        $this->assertSame('step two blew up', $document['error']);
        $this->assertSame(RuntimeException::class, $document['error_class']);
        $this->assertStringContainsString("laraflow:execute {$execution->id}", $document['message']);
        $this->assertSame('failed', $document['execution']['steps'][1]['status']);
    }

    public function test_recover_keeps_its_shape_when_there_is_nothing_to_recover(): void
    {
        ['output' => $output] = $this->callCommand('laraflow:recover', ['--json' => true]);

        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $document['candidates']);
        $this->assertFalse($document['sync']);
        $this->assertSame(0, $document['counts']['failed'], "jq '.counts.failed' must not error on an empty day.");
        $this->assertFalse($document['needs_attention']);
    }

    public function test_summary_reports_counts_and_attention_as_data(): void
    {
        $this->callCommand('laraflow:demo');
        $this->callCommand('laraflow:demo', ['--fail' => true]);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary', ['--json' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $document['total']);
        $this->assertSame(1, $document['counts']['completed']);
        $this->assertSame(1, $document['counts']['failed']);
        $this->assertFalse($document['needs_attention']);
        $this->assertSame([], $document['attention']['due_retries']);
        $this->assertNull($document['attention']['oldest_unfinished']);
    }

    public function test_the_scheduling_commands_report_their_decisions_as_data(): void
    {
        config()->set('laraflow.schedules.workflows', [NightlyWorkflow::class]);

        ['output' => $output] = $this->callCommand('laraflow:schedule', ['--json' => true]);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($document['enabled']);
        $this->assertSame('not_due', $document['actions'][0]['outcome']);

        $this->clock->set('2026-11-04 02:00:30');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:schedule', ['--json' => true]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('started', $document['actions'][0]['outcome']);
        $this->assertSame('2026-11-04T02:00:00+00:00', $document['actions'][0]['tick_at']);
        $this->assertIsInt($document['actions'][0]['execution']);

        ['output' => $output] = $this->callCommand('laraflow:schedules', ['--json' => true]);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(NightlyWorkflow::class, $document['schedules'][0]['workflow']);
        $this->assertSame('ok', $document['schedules'][0]['state']);
        $this->assertSame('started', $document['schedules'][0]['last_tick']['outcome']);
        $this->assertSame('2026-11-05T02:00:00+00:00', $document['schedules'][0]['next_due_at']);
    }
}
