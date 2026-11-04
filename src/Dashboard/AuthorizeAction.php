<?php

declare(strict_types=1);

namespace Laraflow\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @internal The second authorization check, on the four action routes
 * alone. Authorize has already let the request look; this asks whether
 * it may also act. The rule is Dashboard::checkAction(); with nothing
 * configured it is the view rule, so this changes nothing until an
 * application asks for the split (docs/dashboard.md, "Authorization").
 */
final class AuthorizeAction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Dashboard::checkAction($request)) {
            throw new HttpException(403, 'This request may view the Laraflow dashboard but is not authorized to act on executions.');
        }

        return $next($request);
    }
}
