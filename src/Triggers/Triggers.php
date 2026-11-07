<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use LogicException;

/**
 * The one place an application opts in to signals over HTTP. Nothing
 * here runs unless the application calls it: no route is registered by
 * the service provider, so an application that never mounts triggers
 * has no trigger endpoint — not a disabled one, an absent one.
 *
 * Mounting with no signing key refuses at the mount, by name: an
 * endpoint that could verify nothing would refuse everything and look
 * like a bug.
 */
final class Triggers
{
    /**
     * Mount the trigger routes.
     *
     * @param  string|null  $path  Where they answer; `laraflow.triggers.path` when null.
     * @param  list<string>|null  $middleware  What every route runs through first;
     *                                         `laraflow.triggers.middleware` when null. Pass `[]`
     *                                         when mounting inside a group that already supplies it.
     *
     * @throws LogicException when the router is not available, or no usable key is configured.
     */
    public static function routes(?string $path = null, ?array $middleware = null): void
    {
        $app = self::app();

        self::assertDependencies($app);

        /** @var Config $config */
        $config = $app->make(Config::class);

        // Refuse the mount, not the first request: the key is the
        // endpoint's whole authentication.
        TriggerSignature::keys($config);

        $path ??= self::string($config->get('laraflow.triggers.path'), 'laraflow/triggers');
        $middleware ??= self::middleware($config->get('laraflow.triggers.middleware'));

        /** @var Router $router */
        $router = $app->make(Router::class);

        $router->group([
            'prefix' => trim($path, '/'),
            'as' => 'laraflow.triggers.',
            'middleware' => $middleware,
        ], function (Router $router): void {
            // Integer ids on every signature (docs/public-api.md), so
            // anything else is not a route. Verification is a middleware
            // on this route alone, so it runs before the controller reads
            // a thing. POST only: a signal by GET is a route that does
            // not exist.
            $router->post('executions/{id}/signals/{signal}', [TriggerController::class, 'signal'])
                ->whereNumber('id')
                ->middleware(VerifyTriggerSignature::class)
                ->name('signal');

            // One segment, a slug: resolved against the registry at
            // request time, so a trigger registered after the mount is
            // served too. Cannot shadow the signed endpoint, whose path
            // is three segments or more.
            $router->post('{trigger}', [TriggerController::class, 'named'])
                ->where('trigger', '[a-z0-9][a-z0-9_-]*')
                ->name('named');
        });

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Register a named trigger: a provider endpoint behind the
     * provider's own verification and the application's mapping of its
     * request to one signal or nothing. Call it from a service
     * provider's boot(), before or after routes().
     *
     * @param  class-string<TriggerVerifier>  $verifier
     * @param  Closure(\Illuminate\Http\Request): mixed  $resolver  Returns a SignalIntent, or null to acknowledge and ignore.
     *
     * @throws InvalidArgumentException for a name that is not a slug, is reserved, or is taken, or a verifier that is not one.
     */
    public static function register(string $name, string $verifier, Closure $resolver): void
    {
        self::app()->make(TriggerRegistry::class)->register(new NamedTrigger($name, $verifier, $resolver));
    }

    private static function assertDependencies(Container $app): void
    {
        if (! class_exists(Router::class) || ! $app->bound(Router::class)) {
            throw new LogicException(
                'Laraflow triggers need illuminate/routing, which the package suggests rather than requires. '
                .'Install it, or do not call Triggers::routes().'
            );
        }
    }

    private static function app(): Container
    {
        $app = Facade::getFacadeApplication();

        if (! $app instanceof Container) {
            throw new LogicException('Laraflow triggers can only be mounted inside a Laravel application.');
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
            return ['api'];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
