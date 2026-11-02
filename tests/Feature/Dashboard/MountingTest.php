<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laraflow\Dashboard\Dashboard;
use Laraflow\Tests\TestCase;
use LogicException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * docs/dashboard.md, "Off unless mounted": an application that has not
 * called Dashboard::routes() has no dashboard — not a disabled one, an
 * absent one. And once mounted, it answers where it was told to.
 */
class MountingTest extends TestCase
{
    public function test_an_application_that_has_not_mounted_the_dashboard_has_none(): void
    {
        $this->assertFalse(Route::has('laraflow.dashboard'), 'No route is registered by the service provider.');
        $this->assertFalse(Route::has('laraflow.executions'));

        $this->get('/laraflow')->assertNotFound();
        $this->get('/laraflow/executions')->assertNotFound();
    }

    public function test_mounting_registers_the_dashboard_at_the_configured_path(): void
    {
        $this->app['env'] = 'local';

        Dashboard::routes();

        $this->assertTrue(Route::has('laraflow.dashboard'));
        $this->assertTrue(Route::has('laraflow.executions'));

        $this->get('/laraflow')->assertRedirect('/laraflow/executions');
        $this->get('/laraflow/executions')->assertOk();
    }

    public function test_the_path_comes_from_configuration_or_the_call(): void
    {
        $this->app['env'] = 'local';
        config()->set('laraflow.dashboard.path', 'ops/workflows');

        Dashboard::routes();

        $this->get('/ops/workflows/executions')->assertOk();
        $this->get('/laraflow/executions')->assertNotFound();

        // An explicit path wins over the configured one, for an
        // application mounting the dashboard inside a group of its own.
        Dashboard::routes(path: 'elsewhere');

        $this->get('/elsewhere/executions')->assertOk();
    }

    public function test_the_configured_middleware_runs_before_the_dashboards_own_check(): void
    {
        $this->app['env'] = 'local';

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('laraflow-test-refuse', RefusingMiddleware::class);

        config()->set('laraflow.dashboard.middleware', ['laraflow-test-refuse']);

        Dashboard::routes();

        // The application's middleware answered first: 418, not 200.
        $this->get('/laraflow/executions')->assertStatus(418);

        // And an empty list opts out of the configured stack entirely.
        Dashboard::routes(path: 'bare', middleware: []);

        $this->get('/bare/executions')->assertOk();
    }

    public function test_mounting_without_the_view_layer_refuses_by_name(): void
    {
        // The dependencies are suggested, not required: a container
        // without the view factory is told what to install, at the
        // mount, rather than failing on the first request.
        $this->app->forgetInstance('view');
        unset($this->app['view']);

        try {
            Dashboard::routes();
            $this->fail('Mounting without a view factory should refuse.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('illuminate/view', $exception->getMessage());
            $this->assertStringContainsString('suggests rather than requires', $exception->getMessage());
        }

        $this->assertFalse(Route::has('laraflow.dashboard'), 'Nothing was registered.');
    }
}

/**
 * Stands in for an application middleware the dashboard is mounted
 * behind, answering before the dashboard does.
 */
final class RefusingMiddleware
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        return new Response("I'm a teapot", 418);
    }
}
