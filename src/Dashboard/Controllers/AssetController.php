<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @internal Serves the dashboard's one stylesheet from the package, so
 * installing the dashboard publishes nothing, builds nothing, and
 * reaches no CDN (docs/dashboard.md, "No build step, anywhere").
 *
 * Behind the same authorization as every other dashboard route: the
 * stylesheet reveals nothing, but "nothing reachable without the
 * check" is a simpler rule to hold than a list of exceptions.
 */
final class AssetController
{
    public function stylesheet(Request $request): Response
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/dist/dashboard.css');
        $etag = '"'.hash('xxh128', $css).'"';

        $headers = [
            'Content-Type' => 'text/css; charset=utf-8',
            'ETag' => $etag,
            // Private: it sits behind the authorization check, so a
            // shared cache must not serve it to the next visitor.
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response($css, 200, $headers);
    }
}
