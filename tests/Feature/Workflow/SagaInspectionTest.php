<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Queue;
use Laraflow\Dashboard\Dashboard;
use Laraflow\Tests\Support\FanOutWorkflow;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\RefundableStep;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\ShippableStep;
use Laraflow\Tests\Support\ThrowingStep;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * The Milestone 9 exit criteria, read back: every outcome of a fan-out
 * is visible from `laraflow:show`, and a compensated workflow is
 * replayable from its record — an operator can see what ran, what was
 * undone, and what could not be. The dashboard shows the same, because
 * it reads the same contract.
 */
class SagaInspectionTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_the_console_names_what_a_fan_out_is_waiting_on(): void
    {
        Queue::fake();

        $parent = $this->runner()->run(new FanOutWorkflow);
        $members = WorkflowExecutionModel::query()->where('parent_execution_id', $parent->id)->orderBy('id')->get();

        $this->runner()->execute($this->row($members, 0, 'member')->id, new \Laraflow\Tests\Support\TwoStepWorkflow);

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $parent->id]);

        $this->assertStringContainsString('NotifyMembersStep', $output);
        $this->assertStringContainsString('2 of 3 fan-out member(s) still running', $output);
        $this->assertStringContainsString('next check', $output);
    }

    public function test_the_console_shows_what_ran_what_was_undone_and_what_could_not_be(): void
    {
        PolicyWorkflow::$steps = [RefundableStep::class, ShippableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('the warehouse is on fire');

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        $id = (string) WorkflowExecutionModel::query()->sole()->id;
        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => $id, '--attempts' => true]);

        // What ran, and what failed.
        $this->assertStringContainsString('RefundableStep', $output);
        $this->assertStringContainsString('ShippableStep', $output);
        $this->assertStringContainsString('the warehouse is on fire', $output);

        // What was undone: the compensation rows, marked as undos so a
        // reader does not take them for repeated steps.
        $this->assertSame(2, substr_count($output, 'undo'), 'One marker per compensation row.');

        // And the attempt history carries them, because they are steps.
        $this->assertStringContainsString('Attempt', $output);
    }

    public function test_the_dashboard_shows_the_same_saga(): void
    {
        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        PolicyWorkflow::$steps = [RefundableStep::class, ThrowingStep::class];
        ThrowingStep::$exception = new RuntimeException('boom');

        try {
            $this->runner()->run(new PolicyWorkflow);
        } catch (RuntimeException) {
        }

        $id = WorkflowExecutionModel::query()->sole()->id;

        $this->get("/laraflow/executions/{$id}")
            ->assertOk()
            ->assertSee('RefundableStep')
            ->assertSee('boom')
            // The compensation row is marked, not silently a second
            // RefundableStep.
            ->assertSee('lf-tag--undo', false)
            ->assertSee('A compensation: this row undoes the step it names');
    }

    public function test_the_dashboard_says_what_a_fan_out_is_waiting_on(): void
    {
        Queue::fake();
        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        $parent = $this->runner()->run(new FanOutWorkflow);

        $this->get("/laraflow/executions/{$parent->id}")
            ->assertOk()
            ->assertSee('Waiting.')
            ->assertSee('3 of 3 fan-out member(s) still running');
    }

    public function test_the_members_of_a_group_are_listable_by_the_parent_filter(): void
    {
        Queue::fake();
        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        $parent = $this->runner()->run(new FanOutWorkflow);

        // The parent's page lists its children, which for a fan-out is
        // the group — no new query surface, the Milestone 4 rule.
        $this->get("/laraflow/executions/{$parent->id}")
            ->assertOk()
            ->assertSee('Children')
            ->assertSee('3');
    }

    protected function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
