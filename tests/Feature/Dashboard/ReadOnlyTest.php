<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Illuminate\Support\Facades\Route;
use Laraflow\Dashboard\Dashboard;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;

/**
 * docs/dashboard.md: with `read_only` set, the action routes are not
 * registered — absent, not hidden — and the forms are not rendered
 * because the routes they would post to do not exist.
 */
class ReadOnlyTest extends TestCase
{
    public function test_a_read_only_mount_has_no_action_routes_and_no_forms(): void
    {
        config()->set('laraflow.dashboard.read_only', true);

        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        foreach (['laraflow.actions.start', 'laraflow.actions.execute', 'laraflow.actions.cancel', 'laraflow.actions.signal'] as $name) {
            $this->assertFalse(Route::has($name), "{$name} is absent.");
        }

        StepTwo::$failTimes = 1;

        try {
            $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
        }

        // Not 403: nothing is mounted there. The action paths are
        // 404; the start path shares its URL with the list's GET, so
        // HTTP says 405 — the method is absent, the page is not.
        $this->post('/laraflow/executions/1/execute')->assertNotFound();
        $this->post('/laraflow/executions/1/cancel')->assertNotFound();
        $this->post('/laraflow/executions/1/signal')->assertNotFound();
        $this->post('/laraflow/executions')->assertStatus(405);

        $this->get('/laraflow/executions/1')->assertOk()
            ->assertSee('Failed.')
            ->assertDontSee('<form', false);

        $this->get('/laraflow/executions')->assertOk()
            ->assertDontSee('Start a workflow')
            // The filter form is a GET and stays: looking is allowed.
            ->assertSee('lf-filters', false);

        $this->assertSame(ExecutionStatus::Failed->value, WorkflowExecutionModel::query()->sole()->status);
    }
}
