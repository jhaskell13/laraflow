<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\CrashingExecutionRepository;
use Laraflow\Tests\Support\CrashingIdempotencyStore;
use Laraflow\Tests\Support\MutableWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepThree;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\IdempotencyStore;
use Laraflow\Workflow\UpgradeCheck;
use Laraflow\Workflow\WorkflowRunner;

/**
 * `laraflow:upgrade` tells an application what it must change before
 * moving to this version. Each check is held to both answers: silent
 * on an installation that is fine, and specific on one that is not.
 */
class UpgradeCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_a_clean_installation_needs_no_action(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:upgrade', ['--json' => true]);

        $this->assertSame(0, $status);

        $document = $this->document($output);
        $this->assertFalse($document['needs_action']);

        $byName = $this->byName($document);

        // The suite migrates, publishes nothing, and rebinds nothing.
        foreach (['migrations', 'published_migrations', 'config', 'custom_bindings', 'in_flight_plans'] as $check) {
            $this->assertSame(UpgradeCheck::OK, $byName[$check]['status'], "[{$check}] should be ok on a clean install.");
        }

        // A removal is reported but never fails: only a person can
        // check an application's own scripts for it.
        $this->assertSame(UpgradeCheck::REVIEW, $byName['removed']['status']);
        $this->assertStringContainsString('make:workflow', (string) json_encode($byName['removed']['details']));
    }

    public function test_it_names_the_workflow_whose_in_flight_executions_a_deploy_would_strand(): void
    {
        MutableWorkflow::$steps = [StepOne::class, StepTwo::class];
        $this->app->make(WorkflowRunner::class)->start(new MutableWorkflow);

        // The deploy: the code's steps() moves while an execution
        // created against the old plan is still pending.
        MutableWorkflow::$steps = [StepOne::class, StepThree::class];

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:upgrade', ['--json' => true]);

        $this->assertSame(1, $status, 'A stranded plan must fail the check, so it can gate a deploy.');

        $check = $this->byName($this->document($output))['in_flight_plans'];
        $this->assertSame(UpgradeCheck::ACTION, $check['status']);
        $this->assertStringContainsString(MutableWorkflow::class, $check['details'][0]);
        $this->assertStringContainsString('version()', $check['details'][0]);
        $this->assertStringContainsString('planMigrations()', $check['details'][0]);
    }

    public function test_an_unfinished_execution_whose_plan_still_matches_is_not_reported(): void
    {
        MutableWorkflow::$steps = [StepOne::class, StepTwo::class];
        $this->app->make(WorkflowRunner::class)->start(new MutableWorkflow);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:upgrade', ['--json' => true]);

        $this->assertSame(0, $status);
        $this->assertSame(UpgradeCheck::OK, $this->byName($this->document($output))['in_flight_plans']['status']);
    }

    public function test_it_lists_the_contracts_an_application_has_replaced_without_failing(): void
    {
        $this->app->instance(IdempotencyStore::class, new CrashingIdempotencyStore(
            $this->app->make(IdempotencyStore::class),
            dieOn: 'never',
        ));

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:upgrade', ['--json' => true]);

        $check = $this->byName($this->document($output))['custom_bindings'];

        $this->assertSame(UpgradeCheck::REVIEW, $check['status']);
        $this->assertStringContainsString('CrashingIdempotencyStore', (string) json_encode($check['details']));
        $this->assertSame(0, $status, 'A replaced contract is worth saying; it is not a fault.');
    }

    public function test_the_store_half_of_the_repository_is_called_out_as_the_one_experimental_surface(): void
    {
        $this->app->instance(ExecutionRepository::class, new CrashingExecutionRepository(
            $this->app->make(ExecutionRepository::class),
            dieOnMethod: 'never',
        ));

        ['output' => $output] = $this->callCommand('laraflow:upgrade', ['--json' => true]);

        $details = json_encode($this->byName($this->document($output))['custom_bindings']['details']);
        $this->assertStringContainsString('Experimental', (string) $details);
    }

    public function test_the_human_form_reports_every_check_and_says_when_there_is_nothing_to_do(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:upgrade');

        $this->assertSame(0, $status);

        foreach (['migrations', 'published_migrations', 'config', 'custom_bindings', 'in_flight_plans', 'removed'] as $check) {
            $this->assertStringContainsString($check, $output);
        }

        $this->assertStringContainsString('Nothing to do', $output);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $output): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('laraflow:upgrade', $decoded['command']);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $document): array
    {
        $byName = [];

        /** @var list<array<string, mixed>> $checks */
        $checks = $document['checks'];

        foreach ($checks as $check) {
            $byName[(string) $check['name']] = $check;
        }

        return $byName;
    }
}
