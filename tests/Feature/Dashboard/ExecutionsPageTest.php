<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Illuminate\Support\Facades\Date;
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
 * for every status, filtered by what the schema indexes, paginated the
 * way the repository paginates.
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

        // Newest first, as the console lists them, each a link to itself.
        $response->assertSeeInOrder([
            $this->idLink($waiting->id),
            $this->idLink(2),
            $this->idLink($completed->id),
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

    public function test_the_filters_are_the_ones_the_console_offers_and_mean_the_same(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        Date::setTestNow('2026-11-02 09:00:00');
        $runner->run(new TwoStepWorkflow, [], new ExecutionOptions(correlationId: 'early', tenantId: 'acme'));

        Date::setTestNow('2026-11-02 12:00:00');
        $runner->run(new TwoStepWorkflow, [], new ExecutionOptions(correlationId: 'late', tenantId: 'globex'));

        StepTwo::$failTimes = 1;

        try {
            $runner->run(new TwoStepWorkflow, [], new ExecutionOptions(tenantId: 'acme'));
        } catch (RuntimeException) {
        }

        $runner->run(new ApprovalWorkflow);

        // Status, repeatable, as checkboxes submit it.
        $this->get('/laraflow/executions?status[]=failed&status[]=waiting')
            ->assertOk()->assertSee('Showing 2 of 2 matching executions')
            ->assertSee('<td><span class="lf-status lf-status--failed"', false)
            ->assertSee('<td><span class="lf-status lf-status--waiting"', false)
            // In no row — the filter form offers every status as a
            // checkbox, so the badge itself is always on the page.
            ->assertDontSee('<td><span class="lf-status lf-status--completed"', false);

        // ...and comma-separated, as a hand-typed URL has it.
        $this->get('/laraflow/executions?status=failed,waiting')
            ->assertOk()->assertSee('Showing 2 of 2 matching executions');

        $this->get('/laraflow/executions?tenant=acme')
            ->assertOk()->assertSee('Showing 2 of 2 matching executions');

        $this->get('/laraflow/executions?correlation=late')
            ->assertOk()->assertSee('Showing 1 of 1 matching execution')->assertSee('globex');

        $this->get('/laraflow/executions?workflow='.urlencode(ApprovalWorkflow::class))
            ->assertOk()->assertSee('Showing 1 of 1 matching execution');

        // A creation window: since is inclusive, until exclusive — the
        // same half-open window laraflow:list --since/--until applies.
        $this->get('/laraflow/executions?since=2026-11-02+10:00:00')
            ->assertOk()->assertSee('Showing 3 of 3 matching executions');

        $this->get('/laraflow/executions?until=2026-11-02+12:00:00')
            ->assertOk()->assertSee('Showing 1 of 1 matching execution')->assertSee('early');

        // Filters combine, and the filled form shows what is in force.
        $this->get('/laraflow/executions?tenant=acme&status[]=completed')
            ->assertOk()->assertSee('Showing 1 of 1 matching execution')
            ->assertSee('value="acme"', false)
            ->assertSee('value="completed" checked', false)
            ->assertSee('Clear');
    }

    public function test_a_filter_that_cannot_be_read_is_reported_and_the_rest_still_hold(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $runner->run(new TwoStepWorkflow, [], new ExecutionOptions(tenantId: 'acme'));
        $runner->run(new TwoStepWorkflow, [], new ExecutionOptions(tenantId: 'globex'));

        $response = $this->get('/laraflow/executions?tenant=acme&since=not-a-time&status[]=bogus');

        // Not a 500, not a blank page: the tenant filter still applies,
        // and each unreadable field is named.
        $response->assertOk();
        $response->assertSee('Showing 1 of 1 matching execution');
        $response->assertSee('must be a date or time');
        $response->assertSee('Unknown status [bogus]');
        $response->assertSee('role="alert"', false);
    }

    public function test_an_empty_window_is_reported_rather_than_applied(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $this->get('/laraflow/executions?since=2026-11-02+12:00:00&until=2026-11-02+09:00:00')
            ->assertOk()
            ->assertSee('the window is empty')
            ->assertSee('Showing 1 of 1');
    }

    public function test_pages_follow_the_repositorys_cursor_forward_and_the_trail_back(): void
    {
        config()->set('laraflow.dashboard.per_page', 2);

        $runner = $this->app->make(WorkflowRunner::class);

        foreach (range(1, 5) as $i) {
            $runner->run(new TwoStepWorkflow, ['i' => $i]);
        }

        $first = $this->get('/laraflow/executions');

        $first->assertOk();
        $first->assertSee('Showing 2 of 5 executions');
        $first->assertSee($this->idLink(5), false);
        $first->assertSee($this->idLink(4), false);
        $first->assertDontSee($this->idLink(3), false);
        $first->assertSee('rel="next"', false);
        $first->assertDontSee('rel="prev"', false);

        // Forward: the next link carries the cursor the repository issued.
        $second = $this->get($this->link($first->getContent(), 'next'));

        $second->assertOk();
        $second->assertSee($this->idLink(3), false);
        $second->assertSee($this->idLink(2), false);
        $second->assertDontSee($this->idLink(5), false);
        $second->assertSee('« First');
        $second->assertSee('rel="prev"', false);

        $third = $this->get($this->link($second->getContent(), 'next'));

        $third->assertSee($this->idLink(1), false);
        $third->assertSee('End of list');
        $third->assertDontSee('rel="next"', false);

        // Back: previous replays a cursor already issued, never an
        // offset, so page two from page three is exactly page two.
        $backToSecond = $this->get($this->link($third->getContent(), 'prev'));

        $backToSecond->assertSee($this->idLink(3), false);
        $backToSecond->assertSee($this->idLink(2), false);
        $backToSecond->assertDontSee($this->idLink(1), false);

        // ...and the page before the second is the first, with no cursor.
        $backToFirst = $this->get($this->link($backToSecond->getContent(), 'prev'));

        $backToFirst->assertSee($this->idLink(5), false);
        $backToFirst->assertDontSee('rel="prev"', false);
    }

    public function test_pagination_links_carry_the_filters_in_force(): void
    {
        config()->set('laraflow.dashboard.per_page', 1);

        $runner = $this->app->make(WorkflowRunner::class);
        $runner->run(new TwoStepWorkflow, [], new ExecutionOptions(tenantId: 'acme'));
        $runner->run(new TwoStepWorkflow, [], new ExecutionOptions(tenantId: 'globex'));
        $runner->run(new TwoStepWorkflow, [], new ExecutionOptions(tenantId: 'acme'));

        $first = $this->get('/laraflow/executions?tenant=acme');
        $first->assertSee('Showing 1 of 2 matching executions');

        $next = $this->link($first->getContent(), 'next');
        $this->assertStringContainsString('tenant=acme', $next, 'The next page is a page of the same set.');

        $this->get($next)->assertSee('Showing 1 of 2 matching executions')->assertSee('End of list');
    }

    public function test_a_cursor_the_repository_did_not_issue_goes_back_to_the_first_page_of_the_same_set(): void
    {
        $this->get('/laraflow/executions?cursor=not-a-cursor&tenant=acme')
            ->assertRedirect('/laraflow/executions?tenant=acme');
    }

    public function test_the_page_size_is_bounded_by_the_repositorys_ceiling(): void
    {
        config()->set('laraflow.dashboard.per_page', 100000);

        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        // Not a repository error: the controller clamps before asking.
        $this->get('/laraflow/executions')->assertOk()->assertSee('Showing 1 of 1 execution');
    }

    public function test_the_workflow_field_suggests_the_workflows_on_the_page_and_no_more(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);
        $runner->run(new TwoStepWorkflow);
        $runner->run(new ApprovalWorkflow);

        // The contract has no method listing every workflow; the field
        // offers what is visible (docs/dashboard.md, "Filtering").
        $response = $this->get('/laraflow/executions');

        $response->assertSee('<option value="'.TwoStepWorkflow::class.'">', false);
        $response->assertSee('<option value="'.ApprovalWorkflow::class.'">', false);

        $this->get('/laraflow/executions?workflow='.urlencode(ApprovalWorkflow::class))
            ->assertDontSee('<option value="'.TwoStepWorkflow::class.'">', false);
    }

    /**
     * The Milestone 8.5 audit: a crafted URL could carry thousands of
     * workflow names, and the overview runs two scans per workflow. The
     * extras are dropped and said, rather than the page refusing.
     */
    public function test_a_filter_carrying_more_values_than_a_person_would_type_is_bounded(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $many = implode(',', array_map(fn (int $i): string => "App\\Workflows\\W{$i}", range(1, 500)));

        $response = $this->get('/laraflow/executions?workflow='.urlencode($many));

        $response->assertOk();
        $response->assertSee('Only the first 20 workflow values were applied; the request carried 500.');

        // The overview runs its scans per workflow, so it is bounded too.
        $this->get('/laraflow?workflow='.urlencode($many))->assertOk()->assertSee('Only the first 20');
    }

    public function test_a_trail_longer_than_any_operator_walked_is_bounded(): void
    {
        config()->set('laraflow.dashboard.per_page', 1);

        $runner = $this->app->make(WorkflowRunner::class);
        $runner->run(new TwoStepWorkflow);
        $runner->run(new TwoStepWorkflow);

        $trail = array_map(fn (int $i): string => "cursor-{$i}", range(1, 500));

        $response = $this->get('/laraflow/executions?'.http_build_query(['trail' => $trail]));

        $response->assertOk();

        // Every page link re-emits the trail, so an unbounded trail is
        // an unbounded page. Fifty is kept, and the first page needs no
        // cursor at all, so nothing is unreachable.
        $this->assertSame(50, substr_count((string) $response->getContent(), 'trail%5B'));
    }

    private function idLink(int $id): string
    {
        return 'class="lf-id" href="http://localhost/laraflow/executions/'.$id.'">'.$id.'</a>';
    }

    private function link(string|false $html, string $rel): string
    {
        if (preg_match('/href="([^"]+)" rel="'.$rel.'"/', (string) $html, $link) !== 1) {
            $this->fail("The page has no rel=\"{$rel}\" link.");
        }

        return html_entity_decode($link[1]);
    }
}
