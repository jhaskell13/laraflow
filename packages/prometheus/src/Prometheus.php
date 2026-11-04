<?php

declare(strict_types=1);

namespace Laraflow\Prometheus;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Laraflow\Prometheus\Http\Authorize;
use Laraflow\Prometheus\Http\MetricsController;
use LogicException;

/**
 * Mounting the scrape endpoint, and deciding who may read it.
 *
 * Like the dashboard, the endpoint is the application's to mount — a
 * metrics page lists every workflow class it runs and how each is
 * failing, which is not something to expose because a package was
 * installed:
 *
 * ```php
 * // routes/web.php or a provider's boot()
 * Laraflow\Prometheus\Prometheus::routes();
 * ```
 *
 * **Who may scrape**, decided in this order:
 *
 * 1. A callback given to `Prometheus::auth()` decides alone.
 * 2. With `laraflow-prometheus.token` set, a request must carry it as
 *    `Authorization: Bearer …` — what Prometheus's `bearer_token`
 *    scrape option sends.
 * 3. Otherwise only the `local` environment is let in, and every other
 *    environment is denied: a forgotten rule fails closed.
 *
 * The callback is held in the container rather than a static property,
 * so a long-lived worker (Octane) cannot carry one application's rule
 * into another.
 */
final class Prometheus
{
    public const AUTH = 'laraflow.prometheus.auth';

    /**
     * Register `GET {path}`, named `laraflow.prometheus.metrics`.
     *
     * @param  list<string>|null  $middleware  Defaults to `laraflow-prometheus.route.middleware`, which is empty: a scraper has no session or cookies.
     */
    public static function routes(?string $path = null, ?array $middleware = null): void
    {
        $app = self::app();
        $config = $app->make(Config::class);

        $path ??= self::string($config->get('laraflow-prometheus.route.path'), 'laraflow/metrics');
        $middleware ??= self::middleware($config->get('laraflow-prometheus.route.middleware'));

        $router = $app->make(Router::class);

        $router->get(trim($path, '/'), MetricsController::class)
            ->middleware(array_merge($middleware, [Authorize::class]))
            ->name('laraflow.prometheus.metrics');

        // Mounted from a provider's boot(), after Laravel has built its
        // lookups, the name would not resolve without this.
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Decide who may scrape; replaces both other rules.
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        self::app()->instance(self::AUTH, $callback);
    }

    public static function check(Request $request): bool
    {
        $app = self::app();

        if ($app->bound(self::AUTH)) {
            return (bool) $app->make(self::AUTH)($request);
        }

        $token = $app->make(Config::class)->get('laraflow-prometheus.token');

        if (is_string($token) && $token !== '') {
            $given = $request->bearerToken();

            return is_string($given) && hash_equals($token, $given);
        }

        return $app instanceof Application && $app->environment('local');
    }

    private static function app(): Container
    {
        $app = Facade::getFacadeApplication();

        if (! $app instanceof Container) {
            throw new LogicException('Prometheus::routes() needs a booted Laravel application.');
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
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
