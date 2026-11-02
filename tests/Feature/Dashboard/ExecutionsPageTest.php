<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Laraflow\Dashboard\Dashboard;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * docs/dashboard.md: the executions page is what `laraflow:list`
 * prints, as a table — read through the public repository, rendered
 * for every status, paginated the way the repository paginates.
 */
class ExecutionsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();
    }

    public function test_it_lists_executions_newest_first_with_what_the_console_shows(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        $completed = $runner->run(new TwoStepWorkflow, ['order' => 1], new ExecutionOptions(correlationId: 'req-1', tenantId: 'acme'));

        StepTwo::$failTimes = 1;

        try {
            $runner->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Left failed on purpose.
        }

        $waiting = $runner->run(new ApprovalWorkflow);

        $response = $this->get('/laraflow/executions');

        $response->assertOk();
        $response->assertSee('Showing 3 of 3 executions');

        // Newest first, as the console lists them.
        $response->assertSeeInOrder([
            'lf-id">'.$waiting->id,
            'lf-id">2',
            'lf-id">'.$completed->id,
        ], false);

        // Workflow by its short name, the full class a hover away.
        $response->assertSee('TwoStepWorkflow');
        $response->assertSee('ApprovalWorkflow');
        $response->assertSee('title="'.TwoStepWorkflow::class.'"', false);

        // Every status this run produced, as a badge.
        $response->assertSee('lf-status--completed', false);
        $response->assertSee('lf-status--failed', false);
        $response->assertSee('lf-status--waiting', false);

        // Step progress from the one batched read, and the identifiers.
        $response->assertSee('2<span class="lf-muted">/2</span>', false);
        $response->assertSee('1<span class="lf-muted">/2</span>', false);
        $response->assertSee('1<span class="lf-muted">/3</span>', false);
        $response->assertSee('req-1');
        $response->assertSee('acme');
    }

    public function test_an_empty_table_says_how_to_fill_it(): void
    {
        $response = $this->get('/laraflow/executions');

        $response->assertOk();
        $response->assertSee('No executions yet.');
        $response->assertSee('laraflow:start');
        $response->assertDontSee('<table', false);
    }

    public function test_pages_follow_the_repositorys_cursor(): void
    {
        config()->set('laraflow.dashboard.per_page', 2);

        $runner = $this->app->make(WorkflowRunner::class);

        foreach (range(1, 5) as $i) {
            $runner->run(new TwoStepWorkflow, ['i' => $i]);
        }

        $first = $this->get('/laraflow/executions');

        $first->assertOk();
        $first->assertSee('Showing 2 of 5 executions');
        $first->assertSee('lf-id">5', false);
        $first->assertSee('lf-id">4', false);
        $first->assertDontSee('lf-id">3', false);
        $first->assertSee('Next page');
        $first->assertDontSee('First page');

        // The next link carries the cursor the repository issued.
        $second = $this->get($this->nextPageLink($first->getContent()));

        $second->assertOk();
        $second->assertSee('lf-id">3', false);
        $second->assertSee('lf-id">2', false);
        $second->assertDontSee('lf-id">5', false);
        $second->assertSee('First page');
        $second->assertSee('Next page');

        $third = $this->get($this->nextPageLink($second->getContent()));

        $third->assertSee('lf-id">1', false);
        $third->assertSee('End of list');
        $third->assertDontSee('Next page');
    }

    public function test_a_cursor_the_repository_did_not_issue_goes_back_to_the_first_page(): void
    {
        $this->get('/laraflow/executions?cursor=not-a-cursor')
            ->assertRedirect('/laraflow/executions');
    }

    public function test_the_page_size_is_bounded_by_the_repositorys_ceiling(): void
    {
        config()->set('laraflow.dashboard.per_page', 100000);

        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        // Not a repository error: the controller clamps before asking.
        $this->get('/laraflow/executions')->assertOk()->assertSee('Showing 1 of 1 execution');
    }

    public function test_the_front_door_leads_to_the_list(): void
    {
        $this->get('/laraflow')->assertRedirect('/laraflow/executions');
    }

    private function nextPageLink(string|false $html): string
    {
        if (preg_match('/href="([^"]*cursor=[^"]+)" rel="next"/', (string) $html, $link) !== 1) {
            $this->fail('The page has no next-page link carrying a cursor.');
        }

        return html_entity_decode($link[1]);
    }
}
