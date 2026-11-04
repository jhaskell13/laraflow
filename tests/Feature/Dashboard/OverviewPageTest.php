<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Illuminate\Support\Facades\Date;
use Laraflow\Dashboard\Dashboard;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * docs/dashboard.md: the overview is what `laraflow:summary` prints —
 * counts by status and what needs attention, from the same scan, so
 * the console and the browser cannot disagree about what is wrong.
 */
class OverviewPageTest extends TestCase
{
    use RunsArtisanCommands;

    protected function setUp(): void
    {
        parent::setUp();

        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();
    }

    /**
     * The laraflow:summary rule, held in the browser too: a stalled
     * schedule with no executions yet is exactly the state a dead
     * scheduler leaves, and an empty table must not sleep through it.
     */
    public function test_an_empty_table_does_not_hide_a_stalled_schedule(): void
    {
        config()->set('laraflow.schedules.workflows', [\Laraflow\Tests\Support\NightlyWorkflow::class]);

        $clock = new \Laraflow\Tests\Support\FixedClock('2026-11-03 14:30:00');
        $this->app->instance(\Psr\Clock\ClockInterface::class, $clock);

        $this->callCommand('laraflow:schedule');
        $clock->set('2026-11-07 02:01:00');

        $response = $this->get('/laraflow');

        $response->assertOk();
        $response->assertSee('stalled');
        $response->assertSee('NightlyWorkflow');
        $response->assertDontSee('Nothing needs attention');
    }

    public function test_it_counts_by_status_and_links_each_count_to_the_filtered_list(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        $runner->run(new TwoStepWorkflow);
        $runner->run(new TwoStepWorkflow);

        StepTwo::$failTimes = 1;

        try {
            $runner->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
        }

        $runner->run(new ApprovalWorkflow);

        $response = $this->get('/laraflow');

        $response->assertOk();
        $response->assertSee('4 executions');
        $response->assertSee('lf-count-card--completed', false);
        $response->assertSeeInOrder(['lf-count-card--completed', '2', 'completed', '50.0%'], false);
        $response->assertSeeInOrder(['lf-count-card--failed', '1', 'failed', '25.0%'], false);
        $response->assertSeeInOrder(['lf-count-card--waiting', '1', 'waiting', '25.0%'], false);
        $response->assertSee('executions?status%5B0%5D=failed', false);
        $response->assertSee('nothing');
    }

    public function test_what_needs_attention_is_what_the_console_says_needs_attention(): void
    {
        Date::setTestNow('2026-11-02 09:00:00');

        $runner = $this->app->make(WorkflowRunner::class);

        // A retry that is due: fails once under a retry policy, then the
        // clock moves past its schedule.
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 60, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $retrying = $runner->run(new PolicyWorkflow, [], new ExecutionOptions(tenantId: 'acme'));
        $this->assertTrue($retrying->isRetrying());

        // Something unfinished and old, for the oldest-unfinished line.
        $runner->start(new TwoStepWorkflow);

        Date::setTestNow('2026-11-02 09:10:00');

        // The console's answer...
        ['status' => $status, 'output' => $console] = $this->callCommand('laraflow:summary');

        $this->assertSame(CommandStatus::FAILURE, $status, 'The console says attention is needed.');
        $this->assertStringContainsString('1 retry is due now', $console);
        $this->assertStringContainsString('execution ['.$retrying->id.']', $console);
        $this->assertStringContainsString('Oldest unfinished execution: ['.$retrying->id.']', $console);

        // ...is the page's answer.
        $page = $this->get('/laraflow');

        $page->assertOk();
        $page->assertSee('Needs attention');
        $page->assertSee('1 retry is due now');
        $page->assertSee('href="http://localhost/laraflow/executions/'.$retrying->id.'"', false);
        $page->assertSee('Oldest unfinished execution');
        $page->assertSee('['.$retrying->id.'] PolicyWorkflow');

        // Scoped the same way, too: another tenant's retry is not this
        // tenant's problem, on the console or on the page.
        $this->assertSame(CommandStatus::SUCCESS, $this->callCommand('laraflow:summary', ['--tenant' => 'globex'])['status']);
        $this->get('/laraflow?tenant=globex')->assertOk()->assertSee('No executions match that filter.');

        $this->assertSame(CommandStatus::FAILURE, $this->callCommand('laraflow:summary', ['--tenant' => 'acme'])['status']);
        $this->get('/laraflow?tenant=acme')->assertOk()->assertSee('1 retry is due now');
    }

    public function test_nothing_to_report_says_so(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $this->get('/laraflow')
            ->assertOk()
            ->assertSee('No retries or waits due, no step past its deadline, no schedule stalled, nothing deferred.')
            ->assertDontSee('is due now');
    }

    public function test_an_empty_installation_says_how_to_start(): void
    {
        $this->get('/laraflow')
            ->assertOk()
            ->assertSee('No executions yet.')
            ->assertSee('laraflow:start')
            ->assertDontSee('Needs attention');
    }
}
