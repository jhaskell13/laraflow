<?php

declare(strict_types=1);

namespace Laraflow\Prometheus\Http;

use Closure;
use Illuminate\Http\Request;
use Laraflow\Prometheus\Prometheus;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal Applies Prometheus::check() to the scrape endpoint.
 */
final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Prometheus::check($request)) {
            // Plain text, no detail: a scraper logs the body, and the
            // rule that refused it is the application's business.
            return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
        }

        return $next($request);
    }
}
