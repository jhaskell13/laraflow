<?php

declare(strict_types=1);

namespace Laraflow\Dashboard;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Laraflow\Dashboard\Controllers\ActionController;
use Laraflow\Dashboard\Controllers\AssetController;
use Laraflow\Dashboard\Controllers\ExecutionController;
use Laraflow\Dashboard\Controllers\OverviewController;
use LogicException;

/**
 * @experimental The dashboard is new in Milestone 8.5; see docs/dashboard.md.
 *
 * The one place an application opts in to the dashboard. Nothing here
 * runs unless the application calls it: no routes are registered by
 * the service provider, so an application that never mounts the
 * dashboard has no dashboard — not a disabled one, an absent one.
 *
 * The authorization rule lives on the container rather than in a
 * static property, so it belongs to one application instance and is
 * gone with it; a test cannot leak its rule into the next.
 */
final class Dashboard
{
    /** The container key the authorization callback is bound under. */
    public const AUTH = 'laraflow.dashboard.auth';

    /** The gate an application may define instead of a callback. */
    public const GATE = 'viewLaraflow';

    /** The container key the action authorization callback is bound under. */
    public const ACT_AUTH = 'laraflow.dashboard.act_auth';

    /**
     * Mount the dashboard.
     *
     * @param  string|null  $path  Where it answers; the configured `laraflow.dashboard.path` when null.
     * @param  list<string>|null  $middleware  What every route runs through first; the configured
     *                                         `laraflow.dashboard.middleware` when null. Pass `[]`
     *                                         when mounting inside a group that already supplies it.
     *
     * @throws LogicException when the router or the view factory is not available — the
     *                        dependencies are suggested, not required (docs/dashboard.md).
     */
    public static function routes(?string $path = null, ?array $middleware = null): void
    {
        $app = self::app();

        self::assertDependencies($app);

        /** @var Config $config */
        $config = $app->make(Config::class);

        $path ??= self::string($config->get('laraflow.dashboard.path'), 'laraflow');
        $middleware ??= self::middleware($config->get('laraflow.dashboard.middleware'));

        /** @var Router $router */
        $router = $app->make(Router::class);

        $router->group([
            'prefix' => trim($path, '/'),
            'as' => 'laraflow.',
            // The application's middleware first, so the session and
            // the authenticated user exist by the time the dashboard's
            // own check runs.
            'middleware' => array_merge($middleware, [Authorize::class]),
        ], function (Router $router): void {
            $router->get('/', OverviewController::class)->name('dashboard');
            $router->get('executions', [ExecutionController::class, 'index'])->name('executions');
            // Integer ids on every signature (docs/public-api.md), so
            // anything else is not a page that might exist: 404 here.
            $router->get('executions/{id}', [ExecutionController::class, 'show'])->whereNumber('id')->name('executions.show');
            $router->get('assets/dashboard.css', [AssetController::class, 'stylesheet'])->name('assets.stylesheet');
            $router->get('assets/dashboard.js', [AssetController::class, 'script'])->name('assets.script');

            // Mounted read-only, the action routes are absent — not
            // hidden, not refused: there is nothing to find.
            if (! self::readOnly()) {
                // RequiresSession on these alone: a session is what the
                // application's CSRF protection is built on, and these
                // are the four routes that change something. AuthorizeAction
                // after it: who may act is a second question, asked only
                // of the routes that act.
                $router->middleware([RequiresSession::class, AuthorizeAction::class])->group(function (Router $router): void {
                    $router->post('executions', [ActionController::class, 'start'])->name('actions.start');
                    $router->post('executions/{id}/execute', [ActionController::class, 'execute'])->whereNumber('id')->name('actions.execute');
                    $router->post('executions/{id}/cancel', [ActionController::class, 'cancel'])->whereNumber('id')->name('actions.cancel');
                    $router->post('executions/{id}/signal', [ActionController::class, 'signal'])->whereNumber('id')->name('actions.signal');
                });
            }
        });

        // Laravel refreshes the name and action lookups once, after the
        // application boots. A dashboard mounted after that — from a
        // provider's boot(), or lazily — would answer requests but not
        // route('laraflow.…'), so the lookups are refreshed here.
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Decide who may use the dashboard.
     *
     * The callback receives the request and answers yes or no. It
     * replaces the default rule entirely; see check().
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        self::app()->instance(self::AUTH, $callback);
    }

    /**
     * Whether this request may use the dashboard.
     *
     * The application's callback when it gave one. Otherwise the local
     * environment, or a `viewLaraflow` gate the application defined that
     * allows the request's user. Anything else is denied: a dashboard
     * mounted in production without an explicit rule denies everyone,
     * which is the failure mode that gets noticed and fixed.
     */
    public static function check(Request $request): bool
    {
        $app = self::app();

        if ($app->bound(self::AUTH)) {
            /** @var Closure(Request): bool $callback */
            $callback = $app->make(self::AUTH);

            return (bool) $callback($request);
        }

        if ($app instanceof Application && $app->environment('local')) {
            return true;
        }

        if (! $app->bound(Gate::class)) {
            return false;
        }

        /** @var Gate $gate */
        $gate = $app->make(Gate::class);

        return $gate->has(self::GATE) && $gate->forUser($request->user())->check(self::GATE);
    }

    /**
     * Decide who may use the actions — start, execute, cancel, signal —
     * separately from who may look. Asked after check() has already
     * passed, so it narrows the viewers; it cannot admit anyone the
     * view rule refused.
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function authActions(Closure $callback): void
    {
        self::app()->instance(self::ACT_AUTH, $callback);
    }

    /**
     * Whether this request may use the actions, given that it may use
     * the dashboard at all.
     *
     * The application's authActions() callback when it gave one.
     * Otherwise the gate named by `laraflow.dashboard.action_gate`, when
     * one is configured — and a configured gate the application never
     * defined denies, since a name in config is a statement that acting
     * is restricted, and a typo must not quietly undo it. With neither,
     * the view rule is the action rule, as it was before the two were
     * separable: an application that never asks for the split behaves
     * exactly as it did.
     */
    public static function checkAction(Request $request): bool
    {
        $app = self::app();

        if (! self::check($request)) {
            return false;
        }

        if ($app->bound(self::ACT_AUTH)) {
            /** @var Closure(Request): bool $callback */
            $callback = $app->make(self::ACT_AUTH);

            return (bool) $callback($request);
        }

        $ability = $app->make(Config::class)->get('laraflow.dashboard.action_gate');

        if (! is_string($ability) || trim($ability) === '') {
            return true;
        }

        if (! $app->bound(Gate::class)) {
            return false;
        }

        /** @var Gate $gate */
        $gate = $app->make(Gate::class);

        return $gate->has($ability) && $gate->forUser($request->user())->check($ability);
    }

    /**
     * Whether the dashboard was mounted to look and not touch. Read by
     * the route registration, so with this set the action routes are
     * absent rather than hidden.
     */
    public static function readOnly(): bool
    {
        return (bool) self::app()->make(Config::class)->get('laraflow.dashboard.read_only', false);
    }

    /**
     * The dashboard needs what the package only suggests. Said here, at
     * the mount, with the Composer names, rather than as a container
     * resolution failure on the first request.
     */
    private static function assertDependencies(Container $app): void
    {
        $missing = [];

        if (! class_exists(Router::class) || ! $app->bound(Router::class)) {
            $missing[] = 'illuminate/routing';
        }

        if (! $app->bound('view')) {
            $missing[] = 'illuminate/view';
        }

        if ($missing !== []) {
            throw new LogicException(
                'The Laraflow dashboard needs '.implode(' and ', $missing).', which the package suggests '
                .'rather than requires. Install them, or do not call Dashboard::routes().'
            );
        }
    }

    private static function app(): Container
    {
        $app = Facade::getFacadeApplication();

        if (! $app instanceof Container) {
            throw new LogicException('The Laraflow dashboard can only be mounted inside a Laravel application.');
        }

        return $app;
    }

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) && trim($value, '/') !== '' ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private static function middleware(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return ['web'];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
