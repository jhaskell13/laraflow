<?php

declare(strict_types=1);

namespace Laraflow\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @internal The one authorization check every mounted dashboard route
 * passes through — pages, actions, and the stylesheet alike. The rule
 * itself is Dashboard::check(); this only applies it.
 */
final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Dashboard::check($request)) {
            // 403 rather than 404: the application mounted the dashboard
            // here on purpose, and a wrong rule should look like one.
            throw new HttpException(403, 'This request is not authorized to use the Laraflow dashboard.');
        }

        return $next($request);
    }
}
