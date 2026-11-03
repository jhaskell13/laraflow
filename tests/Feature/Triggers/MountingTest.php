<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Triggers;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laraflow\Tests\TestCase;
use Laraflow\Triggers\Triggers;
use LogicException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * docs/triggers.md, "Off unless mounted" and "Mounting": no routes
 * until an application calls Triggers::routes(); once mounted, where
 * it was told, behind the middleware it was told, and never without a
 * usable key.
 */
class MountingTest extends TestCase
{
    private const KEY = 'test-key-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('laraflow.triggers.key', self::KEY);
    }

    public function test_an_application_that_has_not_mounted_triggers_has_none(): void
    {
        $this->assertFalse(Route::has('laraflow.triggers.signal'));
        $this->assertFalse(Route::has('laraflow.triggers.named'));

        $this->post('/laraflow/triggers/executions/1/signals/approval')->assertNotFound();
        $this->post('/laraflow/triggers/stripe')->assertNotFound();
    }

    public function test_mounting_registers_the_endpoints_at_the_configured_path(): void
    {
        Triggers::routes();

        $this->assertTrue(Route::has('laraflow.triggers.signal'));
        $this->assertTrue(Route::has('laraflow.triggers.named'));

        // Mounted and unsigned is refused, not missing: the route exists.
        $this->post('/laraflow/triggers/executions/1/signals/approval')->assertUnauthorized();
    }

    public function test_the_path_comes_from_configuration_or_the_call(): void
    {
        config()->set('laraflow.triggers.path', 'hooks/laraflow');

        Triggers::routes();

        $this->post('/hooks/laraflow/executions/1/signals/approval')->assertUnauthorized();
        $this->post('/laraflow/triggers/executions/1/signals/approval')->assertNotFound();

        Triggers::routes(path: 'elsewhere');

        $this->post('/elsewhere/executions/1/signals/approval')->assertUnauthorized();
    }

    public function test_the_configured_middleware_runs_before_verification(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('laraflow-test-refuse', RefusingMiddleware::class);

        config()->set('laraflow.triggers.middleware', ['laraflow-test-refuse']);

        Triggers::routes();

        // The application's own middleware answers first — a rate limit
        // would, exactly here — and the signature check never runs.
        $this->post('/laraflow/triggers/executions/1/signals/approval')->assertStatus(418);
        $this->post('/laraflow/triggers/stripe')->assertStatus(418);
    }

    public function test_a_get_is_not_a_route_that_changes_anything(): void
    {
        Triggers::routes();

        $this->get('/laraflow/triggers/executions/1/signals/approval')->assertMethodNotAllowed();
        $this->get('/laraflow/triggers/stripe')->assertMethodNotAllowed();
    }

    public function test_mounting_without_a_key_refuses_at_the_mount_by_name(): void
    {
        config()->set('laraflow.triggers.key', null);

        try {
            Triggers::routes();
            $this->fail('A mount with no key must refuse.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('laraflow.triggers.key is not set', $exception->getMessage());
        }

        $this->assertFalse(Route::has('laraflow.triggers.signal'), 'Nothing was registered.');
    }

    public function test_mounting_with_a_short_key_refuses_at_the_mount(): void
    {
        config()->set('laraflow.triggers.key', 'short');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least 32 bytes');

        Triggers::routes();
    }
}

final class RefusingMiddleware
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        return new Response("I'm a teapot", 418);
    }
}
