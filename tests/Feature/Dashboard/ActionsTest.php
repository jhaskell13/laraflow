<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Laraflow\Dashboard\Dashboard;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\PolicyWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;
use Throwable;

/**
 * docs/dashboard.md, "Actions": four, each the browser form of a
 * console command, each through the same service, each ending on the
 * execution's page with what became of it said the way the console
 * says it.
 */
class ActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();
    }

    public function test_a_failed_execution_is_recovered_from_its_checkpoint_in_the_request(): void
    {
        $this->failedExecution();

        $response = $this->followingRedirects()->post('/laraflow/executions/1/execute');

        $response->assertOk();
        $response->assertSee('Execution completed');
        $response->assertSee('lf-status--completed', false);

        $this->assertSame(ExecutionStatus::Completed->value, WorkflowExecutionModel::query()->sole()->status);
        $this->assertSame(1, StepOne::$runs, 'The checkpointed step did not run again.');
        $this->assertSame(2, StepTwo::$runs, 'The failed step ran again.');
    }

    public function test_execute_can_hand_the_execution_to_the_queue_instead(): void
    {
        Queue::fake();

        $this->failedExecution();

        $response = $this->followingRedirects()->post('/laraflow/executions/1/execute', ['queue' => '1']);

        $response->assertOk();
        // The same sentence the console prints: one decision, one wording.
        $response->assertSee('Queued as execution 1');
        $response->assertSee('A worker will pick it up');

        Queue::assertPushed(ExecuteWorkflowJob::class, fn (ExecuteWorkflowJob $job): bool => $job->executionId === 1);
        $this->assertSame(1, StepTwo::$runs, 'Nothing ran in this request: only the attempt that failed.');
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_execute_on_a_sync_queue_reports_what_ran_rather_than_queued(): void
    {
        // No Queue::fake(): the sync connection runs the job inside the
        // dispatch call — the Phase C audit's first finding, held here.
        $this->failedExecution();

        $response = $this->followingRedirects()->post('/laraflow/executions/1/execute', ['queue' => '1']);

        $response->assertOk();
        $response->assertSee('Execution completed');
        $response->assertDontSee('Queued.');
    }

    public function test_a_retrying_execution_runs_early_only_when_told_to(): void
    {
        PolicyWorkflow::$steps = [StepOne::class, StepTwo::class];
        PolicyWorkflow::$policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 3600, jitter: 0.0);
        StepTwo::$failTimes = 1;

        $this->app->make(WorkflowRunner::class)->run(new PolicyWorkflow);
        $this->assertSame(ExecutionStatus::Retrying->value, WorkflowExecutionModel::query()->sole()->status);

        // Without --now the schedule is respected and the refusal is
        // the runtime's own message, as the console prints it.
        $this->followingRedirects()->post('/laraflow/executions/1/execute')
            ->assertOk()->assertSee('not due until');

        $this->followingRedirects()->post('/laraflow/executions/1/execute', ['now' => '1'])
            ->assertOk()->assertSee('Execution completed');
    }

    public function test_a_step_failure_during_execute_is_said_and_persisted(): void
    {
        StepTwo::$failTimes = 2;

        $this->failedExecution();

        $response = $this->followingRedirects()->post('/laraflow/executions/1/execute');

        $response->assertOk();
        $response->assertSee('A step failed: step two blew up');
        $response->assertSee('The failure is persisted');
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
    }

    /**
     * M10 audit: the flash is rendered to a browser, so a step's
     * exception reaches it through the configured redactor and the
     * configured length, as it reaches the execution row.
     */
    public function test_a_step_failure_is_flashed_redacted_and_truncated(): void
    {
        $this->app->instance(ErrorRedactor::class, new class implements ErrorRedactor
        {
            public function redact(Throwable $exception, string $message): string
            {
                return str_replace('blew up', '[redacted] and then some more words', $message);
            }
        });
        $this->app->instance(RunnerOptions::class, new RunnerOptions(maxErrorLength: 30));

        StepTwo::$failTimes = 2;
        $this->failedExecution();

        $response = $this->followingRedirects()->post('/laraflow/executions/1/execute');

        $response->assertOk();
        $response->assertDontSee('blew up');
        $response->assertSee('A step failed: step two [redacted] and then', false);
        $response->assertDontSee('then some more words');
    }

    /**
     * M10 audit: a delivered signal goes through Handoff, so a step that
     * runs and fails inline on the sync connection is said as a step
     * failure — not as "could not be dispatched" — and redacted.
     */
    public function test_a_delivered_signal_whose_step_fails_inline_is_reported_as_a_step_failure(): void
    {
        $this->app->instance(ErrorRedactor::class, new class implements ErrorRedactor
        {
            public function redact(Throwable $exception, string $message): string
            {
                return str_replace('blew up', '[redacted]', $message);
            }
        });

        $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow);
        StepTwo::$failTimes = 1;

        $response = $this->followingRedirects()->post('/laraflow/executions/1/signal', [
            'signal' => 'manager-approval',
            'deliver' => '1',
        ]);

        $response->assertOk();
        $response->assertSee('a step failed: step two [redacted]', false);
        $response->assertDontSee('could not be dispatched');
        $response->assertDontSee('blew up');
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
    }

    /**
     * M10 audit: a session without VerifyCsrfToken is not CSRF
     * protection. RequiresSession checks the token itself.
     */
    public function test_a_session_without_the_applications_csrf_middleware_still_requires_the_token(): void
    {
        $this->failedExecution();

        Dashboard::routes(path: 'custom', middleware: [StartSession::class]);
        $this->app['env'] = 'production';

        $page = $this->get('/custom/executions/1');
        $page->assertOk();
        $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', (string) $page->getContent(), $match), 'The form carries the token.');
        $token = $match[1] ?? '';

        $this->post('/custom/executions/1/cancel')->assertStatus(419);
        $this->post('/custom/executions/1/cancel', ['_token' => 'forged'])->assertStatus(419);
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status, 'Nothing happened.');

        $this->post('/custom/executions/1/cancel', ['_token' => $token])->assertRedirect();
        $this->assertSame(ExecutionStatus::Cancelled->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_the_header_token_is_accepted_too(): void
    {
        $this->failedExecution();

        Dashboard::routes(path: 'custom', middleware: [StartSession::class]);
        $this->app['env'] = 'production';

        $token = (string) preg_replace('/.*name="_token" value="([^"]+)".*/s', '$1', (string) $this->get('/custom/executions/1')->getContent());

        $this->post('/custom/executions/1/cancel', [], ['X-CSRF-TOKEN' => $token])->assertRedirect();
        $this->assertSame(ExecutionStatus::Cancelled->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_cancel_records_the_reason_and_cancelled_is_terminal(): void
    {
        $this->failedExecution();

        $response = $this->followingRedirects()->post('/laraflow/executions/1/cancel', ['reason' => 'customer withdrew the order']);

        $response->assertOk();
        $response->assertSee('The execution is cancelled');
        $response->assertSee('customer withdrew the order');
        $response->assertSee('lf-status--cancelled', false);

        $this->assertSame(ExecutionStatus::Cancelled->value, WorkflowExecutionModel::query()->sole()->status);

        // Terminal: the forms that would act on it are gone, and the
        // route refuses with the runtime's own message.
        $response->assertDontSee('name="reason"', false);
        $this->followingRedirects()->post('/laraflow/executions/1/execute')
            ->assertOk()->assertSee('cannot be executed from status [cancelled]');
    }

    public function test_a_completed_execution_cannot_be_cancelled(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $this->followingRedirects()->post('/laraflow/executions/1/cancel')
            ->assertOk()->assertSee('cannot be cancelled');

        $this->assertSame(ExecutionStatus::Completed->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_a_signal_resumes_the_waiting_step_with_its_payload(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow);
        $this->assertSame(ExecutionStatus::Waiting->value, WorkflowExecutionModel::query()->sole()->status);

        // Delivered through the sync queue, so the run happens here.
        $response = $this->followingRedirects()->post('/laraflow/executions/1/signal', [
            'signal' => 'manager-approval',
            'payload' => '{"approved_by": "ops@example.com"}',
            'deliver' => '1',
        ]);

        $response->assertOk();
        $response->assertSee('Execution completed');

        $execution = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Completed->value, $execution->status);
        $this->assertSame('ops@example.com', $execution->context['manager-approval']['approved_by'] ?? null);
    }

    public function test_a_signal_can_be_recorded_without_being_delivered(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow);

        $response = $this->followingRedirects()->post('/laraflow/executions/1/signal', ['signal' => 'manager-approval']);

        $response->assertOk();
        $response->assertSee('recorded. The execution is due now');

        $execution = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Waiting->value, $execution->status, 'Recording is not delivery.');
        $this->assertNotNull($execution->next_retry_at, 'Due immediately, for recover or a worker.');
    }

    public function test_a_buffered_signal_is_not_called_due_and_is_not_dispatched(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow);

        $response = $this->followingRedirects()->post('/laraflow/executions/1/signal', ['signal' => 'later-approval', 'deliver' => '1']);

        $response->assertOk();
        $response->assertSee('buffered. The execution is not waiting on it yet');
        $response->assertDontSee('is due now');
        $this->assertSame(ExecutionStatus::Waiting->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_a_signal_the_execution_is_not_waiting_on_is_refused_by_name(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow);

        $this->followingRedirects()->post('/laraflow/executions/1/signal', ['signal' => 'manager-approval', 'payload' => '[1, 2]'])
            ->assertOk()->assertSee('signal payload must be a JSON object');

        $this->assertSame(ExecutionStatus::Waiting->value, WorkflowExecutionModel::query()->sole()->status);

        // Refused by name once nothing can park again. (While it is
        // unfinished, a signal for another name is kept in its inbox.)
        $this->app->make(WorkflowRunner::class)->cancel(1);

        $this->followingRedirects()->post('/laraflow/executions/1/signal', ['signal' => 'wrong-signal'])
            ->assertOk()->assertSee('wrong-signal')->assertSee('not waiting');
    }

    public function test_start_creates_and_runs_a_workflow_by_name_with_input_and_identifiers(): void
    {
        $response = $this->followingRedirects()->post('/laraflow/executions', [
            'workflow' => TwoStepWorkflow::class,
            'input' => '{"order_id": 42}',
            'correlation' => 'req-7',
            'tenant' => 'acme',
        ]);

        $response->assertOk();
        $response->assertSee('Execution completed');
        $response->assertSee('req-7');
        $response->assertSee('acme');

        $execution = WorkflowExecutionModel::query()->sole();
        $this->assertSame(ExecutionStatus::Completed->value, $execution->status);
        $this->assertSame(TwoStepWorkflow::class, $execution->workflow);
        $this->assertEquals(['order_id' => 42], $execution->input);
        $this->assertSame('req-7', $execution->correlation_id);
    }

    public function test_start_can_queue_instead(): void
    {
        Queue::fake();

        $this->followingRedirects()->post('/laraflow/executions', ['workflow' => TwoStepWorkflow::class, 'queue' => '1'])
            ->assertOk()->assertSee('Queued as execution 1');

        $this->assertSame(ExecutionStatus::Pending->value, WorkflowExecutionModel::query()->sole()->status);
        Queue::assertPushed(ExecuteWorkflowJob::class);
    }

    public function test_start_refuses_a_bad_name_or_input_before_anything_is_written_and_shows_the_form_back(): void
    {
        $this->followingRedirects()->post('/laraflow/executions', ['workflow' => 'NoSuchWorkflow', 'input' => '{"a": 1}'])
            ->assertOk()
            ->assertSee('No workflow class named [NoSuchWorkflow]')
            ->assertSee('<details class="lf-card lf-start" open>', false)
            ->assertSee('value="NoSuchWorkflow"', false)
            ->assertSee('{&quot;a&quot;: 1}', false);

        $this->followingRedirects()->post('/laraflow/executions', ['workflow' => TwoStepWorkflow::class, 'input' => 'not json'])
            ->assertOk()->assertSee('input must be valid JSON');

        $this->followingRedirects()->post('/laraflow/executions', ['workflow' => TwoStepWorkflow::class, 'tenant' => str_repeat('x', 256)])
            ->assertOk()->assertSee('tenantId');

        $this->assertSame(0, WorkflowExecutionModel::query()->count(), 'Nothing was persisted.');
    }

    public function test_actions_pass_the_same_authorization_as_the_pages(): void
    {
        $this->failedExecution();

        Dashboard::auth(fn (): bool => false);

        $this->post('/laraflow/executions/1/execute')->assertForbidden();
        $this->post('/laraflow/executions/1/cancel')->assertForbidden();
        $this->post('/laraflow/executions/1/signal', ['signal' => 'x'])->assertForbidden();
        $this->post('/laraflow/executions', ['workflow' => TwoStepWorkflow::class])->assertForbidden();

        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status, 'Nothing happened.');
    }

    /**
     * M10 audit: acting is separable from looking. With no action rule
     * the view rule decides (above); with one, a viewer who fails it can
     * look, is offered no form, and is refused at the route.
     */
    public function test_an_action_callback_narrows_who_may_act(): void
    {
        $this->failedExecution();

        Dashboard::authActions(fn (): bool => false);

        $this->get('/laraflow/executions/1')->assertOk()->assertDontSee('lf-actions', false);
        $this->get('/laraflow/executions')->assertOk()->assertDontSee('lf-start__form', false);
        $this->post('/laraflow/executions/1/execute')->assertForbidden();
        $this->post('/laraflow/executions/1/cancel')->assertForbidden();
        $this->post('/laraflow/executions/1/signal', ['signal' => 'x'])->assertForbidden();
        $this->post('/laraflow/executions', ['workflow' => TwoStepWorkflow::class])->assertForbidden();
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);

        Dashboard::authActions(fn (): bool => true);
        $this->post('/laraflow/executions/1/cancel')->assertRedirect();
        $this->assertSame(ExecutionStatus::Cancelled->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_a_configured_action_gate_is_consulted_and_an_undefined_one_denies(): void
    {
        $this->failedExecution();
        config()->set('laraflow.dashboard.action_gate', 'actOnLaraflow');

        // Named but never defined: denied, not silently open.
        $this->post('/laraflow/executions/1/cancel')->assertForbidden();

        Gate::define('actOnLaraflow', fn ($user = null): bool => false);
        $this->post('/laraflow/executions/1/cancel')->assertForbidden();

        Gate::define('actOnLaraflow', fn ($user = null): bool => true);
        $this->post('/laraflow/executions/1/cancel')->assertRedirect();
        $this->assertSame(ExecutionStatus::Cancelled->value, WorkflowExecutionModel::query()->sole()->status);
    }

    public function test_the_action_rule_cannot_admit_a_request_the_view_rule_refuses(): void
    {
        Dashboard::auth(fn (): bool => false);
        Dashboard::authActions(fn (): bool => true);

        $this->failedExecution();

        $this->post('/laraflow/executions/1/cancel')->assertForbidden();
    }

    public function test_actions_require_the_applications_csrf_token(): void
    {
        // Laravel skips the check under the `testing` environment, so
        // the environment is what the application would run as.
        $this->app['env'] = 'production';

        $this->failedExecution();

        $this->post('/laraflow/executions/1/execute')->assertStatus(419);
        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);

        // The forms carry the token the middleware wants.
        $this->get('/laraflow/executions/1')->assertOk()->assertSee('name="_token"', false);
    }

    public function test_the_forms_offered_match_the_executions_state(): void
    {
        $runner = $this->app->make(WorkflowRunner::class);

        $runner->run(new TwoStepWorkflow);
        $this->get('/laraflow/executions/1')->assertOk()
            ->assertDontSee('lf-actions', false);

        $this->failedExecution();
        $this->get('/laraflow/executions/2')->assertOk()
            ->assertSee('>Recover<', false)->assertSee('Cancel execution')->assertDontSee('name="signal"', false);

        $runner->run(new ApprovalWorkflow);
        $this->get('/laraflow/executions/3')->assertOk()
            ->assertSee('name="signal"', false)->assertSee('value="manager-approval"', false);
    }

    /**
     * The Milestone 8.5 audit: a POST with no session is a POST with
     * nothing checking where it came from, so the dashboard refuses it
     * loudly rather than acting on it.
     */
    public function test_an_action_mounted_without_a_session_is_refused_rather_than_performed(): void
    {
        $this->failedExecution();

        // A bare mount: no `web`, so no session and no CSRF check. The
        // contract allows this for mounting inside a group that already
        // supplies them — outside one, the actions must not run.
        Dashboard::routes(path: 'bare', middleware: []);

        $response = $this->post('/bare/executions/1/execute');

        $response->assertStatus(500);
        $this->assertStringContainsString('has no session', $response->exception?->getMessage() ?? '');
        $this->assertStringContainsString('read-only', $response->exception?->getMessage() ?? '');

        $this->assertSame(
            ExecutionStatus::Failed->value,
            WorkflowExecutionModel::query()->sole()->status,
            'Nothing was performed.',
        );

        // Looking is unaffected: the pages carry no such risk.
        $this->get('/bare/executions/1')->assertOk();
    }

    public function test_every_signallable_wait_gets_its_own_form(): void
    {
        $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow);

        $response = $this->get('/laraflow/executions/1');

        $response->assertOk();
        // One wait here, so one form — the shape the audit's finding
        // was about is held by the console-parity test below.
        $this->assertSame(1, substr_count((string) $response->getContent(), 'name="signal"'));
        $response->assertSee('value="manager-approval"', false);
    }

    private function failedExecution(): void
    {
        StepTwo::$failTimes = max(1, StepTwo::$failTimes);

        try {
            $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
            // Left failed on purpose.
        }
    }
}
