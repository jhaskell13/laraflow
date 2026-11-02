<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Laraflow\Dashboard\Dashboard;
use Laraflow\Tests\Support\ApprovalStep;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\ParentWorkflow;
use Laraflow\Tests\Support\RememberingStep;
use Laraflow\Tests\Support\RememberingWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * docs/dashboard.md: one execution in full is what `laraflow:show
 * --attempts` prints — the record, its steps, each step's attempts,
 * what it is waiting on, its lineage, and the keys it remembered, never
 * their values.
 */
class ExecutionPageTest extends TestCase
{
    use RunsArtisanCommands;

    protected function setUp(): void
    {
        parent::setUp();

        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();
    }

    public function test_it_shows_the_record_its_steps_and_their_attempts(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        StepTwo::$failTimes = 1;

        $execution = $runner->start(new TwoStepWorkflow, ['order_id' => 42], new ExecutionOptions(correlationId: 'req-9', tenantId: 'acme'));

        try {
            $runner->execute($execution->id, new TwoStepWorkflow);
        } catch (RuntimeException) {
        }

        // Recovered from the checkpoint: step one skipped, step two on
        // its second attempt — the history the attempts table shows.
        $runner->execute($execution->id, new TwoStepWorkflow);

        $response = $this->get('/laraflow/executions/'.$execution->id);

        $response->assertOk();
        $response->assertSee('#'.$execution->id);
        $response->assertSee(TwoStepWorkflow::class);
        $response->assertSee('lf-status--completed', false);
        $response->assertSee('req-9');
        $response->assertSee('acme');
        $response->assertSee('never — run in process');

        // The step table, with its outputs and the error the first
        // attempt left — cleared on the step row once it succeeded.
        $response->assertSeeInOrder(['StepOne', 'StepTwo']);
        $response->assertSee('{"n":1}');
        $response->assertSee('{"n":2}');

        // The attempt history: three attempts, one of them failed.
        // Three attempts recorded, counted in the Attempts section
        // itself rather than from the markup around the number
        // (Milestone 8.5 audit).
        $attempts = $this->section($response->getContent(), 'Attempts');
        $this->assertSame(3, substr_count($attempts, '<tr>') - 1, 'Three attempt rows, past the header.');
        $response->assertSee('step two blew up');
        $response->assertSee('lf-status--failed', false);

        // Input and context, pretty-printed (and escaped, as assertSee
        // expects by default).
        $response->assertSee('"order_id": 42');
        $response->assertSee('"one": "from-step-one"');
    }

    public function test_a_failed_execution_says_how_to_recover_it(): void
    {
        StepTwo::$failTimes = 1;

        try {
            $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
        }

        $this->get('/laraflow/executions/1')
            ->assertOk()
            ->assertSee('Failed.')
            ->assertSee('laraflow:execute 1')
            ->assertSee('step two blew up');
    }

    public function test_a_waiting_execution_says_what_wakes_it(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        $runner->run(new ApprovalWorkflow);

        $this->get('/laraflow/executions/1')
            ->assertOk()
            ->assertSee('Waiting.')
            ->assertSee('ApprovalStep')
            ->assertSee('manager-approval')
            ->assertSee('indefinitely')
            ->assertSee('WorkflowRunner::signal()');

        ApprovalStep::$timeoutSeconds = 3600;

        $runner->run(new ApprovalWorkflow);

        $this->get('/laraflow/executions/2')
            ->assertOk()
            ->assertSee('until')
            ->assertDontSee('indefinitely');
    }

    public function test_remembered_work_lists_keys_and_never_values(): void
    {
        RememberingStep::$key = 'charge-42';
        RememberingStep::$value = ['secret' => 'card-ending-4242', 'amount' => 999];

        $this->app->make(WorkflowRunner::class)->run(new RememberingWorkflow);

        $response = $this->get('/laraflow/executions/1');

        $response->assertOk();
        $response->assertSee('Remembered work');

        // docs/idempotency.md: the value is the result of a side effect
        // and may carry anything, so the remembered-work table shows
        // the key, the status, and the times — never the value. (The
        // fixture step returns what it remembered as its output, which
        // is the application's own choice and shows where outputs do.)
        $table = $this->section($response->getContent(), 'Remembered work');

        $this->assertStringContainsString('charge-42', $table);
        $this->assertStringContainsString('settled', $table);
        $this->assertStringNotContainsString('card-ending-4242', $table);
        $this->assertStringNotContainsString('4242', $table);
    }

    public function test_a_parent_links_its_children_and_a_child_its_parent(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        $parent = $runner->run(new ParentWorkflow);

        $children = $this->app->make(ExecutionRepository::class)
            ->list(new \Laraflow\Workflow\ExecutionFilter(parentId: $parent->id));

        $this->assertNotEmpty($children->executions, 'The parent workflow made a child.');
        $child = $children->executions[0];

        $this->get('/laraflow/executions/'.$parent->id)
            ->assertOk()
            ->assertSee('Children')
            ->assertSee('href="http://localhost/laraflow/executions/'.$child->id.'"', false);

        $this->get('/laraflow/executions/'.$child->id)
            ->assertOk()
            ->assertSee('this is a sub-workflow')
            ->assertSee('href="http://localhost/laraflow/executions/'.$parent->id.'"', false);
    }

    public function test_an_unknown_or_malformed_id_is_not_found(): void
    {
        $this->get('/laraflow/executions/999')->assertNotFound();
        $this->get('/laraflow/executions/abc')->assertNotFound();
        $this->get('/laraflow/executions/-1')->assertNotFound();
    }

    /**
     * "The same view with a different renderer" (docs/dashboard.md),
     * tested as parity rather than asserted: whatever laraflow:show
     * says a waiting execution is parked on, the page says too. The
     * Milestone 8.5 audit found the two had diverged — the console
     * loops over every waiting step and the page returned the first.
     */
    public function test_the_page_says_what_the_console_says_about_a_wait(): void
    {
        ApprovalStep::$timeoutSeconds = 3600;

        $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow);

        ['output' => $console] = $this->callCommand('laraflow:show', ['execution' => '1']);
        $page = (string) $this->get('/laraflow/executions/1')->getContent();

        foreach (['ApprovalStep', 'manager-approval'] as $fact) {
            $this->assertStringContainsString($fact, $console, "The console names {$fact}.");
            $this->assertStringContainsString($fact, $page, "The page names {$fact}.");
        }

        // Both say it is waiting, and neither says it is done.
        $this->assertStringContainsString('waiting', $console);
        $this->assertStringContainsString('lf-status--waiting', $page);
        $this->assertStringNotContainsString('Execution completed', $page);
    }

    /**
     * The payloads are shown in full — that is what the page is for —
     * but behind a click, because an application that puts a secret in
     * its workflow input would otherwise put it on screen.
     */
    public function test_input_and_context_are_shown_in_full_but_collapsed(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow, ['order_id' => 42, 'note' => str_repeat('x', 300)]);

        $response = $this->get('/laraflow/executions/1');

        $response->assertOk();
        // In full: the console truncates its one line at 100 characters.
        $response->assertSee(str_repeat('x', 300));
        $response->assertSee('"order_id": 42');

        // Collapsed: a <details> with no `open`, so nothing is rendered
        // expanded on an operator's screen by default.
        $html = (string) $response->getContent();
        $this->assertStringContainsString('<details class="lf-payload">', $html);
        $this->assertStringNotContainsString('<details class="lf-payload" open>', $html);
        $response->assertSee('2 keys');
    }

    /**
     * The markup of one card, from its title to the end of its section.
     *
     * Anchored on the card's heading, not on the word: "Attempts" is
     * also a column in the step table above it, and scoping to the
     * first occurrence scoped to the wrong card.
     */
    private function section(string|false $html, string $title): string
    {
        $html = (string) $html;
        $start = strpos($html, 'lf-card__title">'.$title);

        if ($start === false) {
            $start = strpos($html, $title);
        }
        $this->assertNotFalse($start, "The page has a [{$title}] section.");
        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
