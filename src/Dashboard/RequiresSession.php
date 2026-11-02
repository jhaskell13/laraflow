<?php

declare(strict_types=1);

namespace Laraflow\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @internal Refuses a state-changing action that is not running behind
 * a session, and therefore not behind CSRF protection.
 *
 * The package does not ship its own CSRF check: the application owns
 * the HTTP boundary and `web` supplies one. But the mount deliberately
 * lets an application pass its own middleware — `Dashboard::routes(path:
 * 'workflows', middleware: [])` is in the contract, for mounting inside
 * a group that already carries the stack — and an empty list given
 * *outside* such a group leaves four POST routes with nothing checking
 * the origin of a request. The actions would still work, so nothing
 * would notice until someone's page cancelled a production execution
 * cross-origin.
 *
 * A session is the thing Laravel's CSRF protection is built on, so its
 * absence is the detectable shape of that mistake. Misconfiguration
 * must be loud — the same rule the configured error redactor follows.
 */
final class RequiresSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw new HttpException(500, sprintf(
                'The Laraflow dashboard refuses a %s to [%s] because this request has no session, so nothing is '
                .'checking that it came from your dashboard. Mount the dashboard behind middleware that starts one '
                .'— the `web` group does — either through laraflow.dashboard.middleware or by nesting '
                .'Dashboard::routes() inside a group that already supplies it. Mount it read-only if it should '
                .'never act. See docs/dashboard.md.',
                $request->getMethod(),
                $request->path(),
            ));
        }

        return $next($request);
    }
}
