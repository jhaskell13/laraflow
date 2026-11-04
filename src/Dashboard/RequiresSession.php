<?php

declare(strict_types=1);

namespace Laraflow\Dashboard;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
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
 *
 * A session is not, on its own, a CSRF check (Milestone 10 audit): a
 * custom stack of `StartSession` without `VerifyCsrfToken` passes the
 * first test and still leaves the four routes forgeable. So the token
 * is checked here too — the request's `_token` field, or its
 * `X-CSRF-TOKEN` header, against the session's — with hash_equals, and
 * a mismatch is the 419 Laravel's own middleware answers. Behind `web`
 * this is the same comparison run twice, which costs nothing; behind
 * anything less it is the only one. The dashboard's forms all carry
 * `@csrf`, so nothing the dashboard sends is refused. The encrypted
 * `X-XSRF-TOKEN` cookie header is not accepted: decrypting it would
 * need the application's encrypter, and nothing the dashboard sends
 * uses it.
 *
 * Skipped while running unit tests, exactly as VerifyCsrfToken skips
 * itself, so an application's own feature tests that post to the
 * dashboard keep working.
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

        if (! $this->runningUnitTests() && ! $this->tokenMatches($request)) {
            // The message names no token: the response goes to whoever
            // sent the forged request.
            throw new HttpException(419, 'The Laraflow dashboard refused this action: its CSRF token is missing or '
                .'does not match the session. Reload the page and try again.');
        }

        return $next($request);
    }

    private function tokenMatches(Request $request): bool
    {
        $expected = (string) $request->session()->token();
        $given = $request->input('_token');

        if (! is_string($given) || $given === '') {
            $given = $request->header('X-CSRF-TOKEN');
        }

        return $expected !== ''
            && is_string($given) && $given !== ''
            && hash_equals($expected, $given);
    }

    private function runningUnitTests(): bool
    {
        $app = Facade::getFacadeApplication();

        return $app instanceof Application && $app->runningInConsole() && $app->runningUnitTests();
    }
}
