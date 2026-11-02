<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laraflow\Dashboard\Dashboard;
use Laraflow\Tests\TestCase;

/**
 * docs/dashboard.md, "Denied unless allowed": one check on every
 * mounted route, defaulting to the local environment and nothing else.
 * The suite runs as `testing`, so the default here is denial — which
 * is the case that matters, since production is not `local` either.
 */
class AuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Dashboard::routes();
    }

    public function test_outside_local_with_no_rule_everyone_is_denied(): void
    {
        $this->assertSame('testing', $this->app->environment());

        $this->get('/laraflow/executions')->assertForbidden();
        $this->get('/laraflow')->assertForbidden();
    }

    public function test_the_local_environment_is_allowed_by_default(): void
    {
        $this->app['env'] = 'local';

        $this->get('/laraflow/executions')->assertOk();
    }

    public function test_an_application_callback_decides(): void
    {
        Dashboard::auth(fn (Request $request): bool => $request->query('operator') === 'yes');

        $this->get('/laraflow/executions?operator=yes')->assertOk();
        $this->get('/laraflow/executions?operator=no')->assertForbidden();
        $this->get('/laraflow/executions')->assertForbidden();
    }

    public function test_the_callback_replaces_the_default_rule_entirely(): void
    {
        // Even local is denied once the application has said who may.
        $this->app['env'] = 'local';

        Dashboard::auth(fn (Request $request): bool => false);

        $this->get('/laraflow/executions')->assertForbidden();
    }

    public function test_a_view_laraflow_gate_is_honoured_without_a_callback(): void
    {
        Gate::define(Dashboard::GATE, fn (?object $user = null): bool => true);

        $this->get('/laraflow/executions')->assertOk();

        Gate::define(Dashboard::GATE, fn (?object $user = null): bool => false);

        $this->get('/laraflow/executions')->assertForbidden();
    }

    public function test_the_rule_belongs_to_the_application_instance(): void
    {
        // Bound on the container, not held in a static: the previous
        // test's callback and gate are gone with their application, so
        // this fresh one is back to the default — denial.
        $this->assertFalse($this->app->bound(Dashboard::AUTH));
        $this->assertFalse(Gate::has(Dashboard::GATE));

        $this->get('/laraflow/executions')->assertForbidden();
    }
}
