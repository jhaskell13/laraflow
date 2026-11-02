<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @internal Serves the dashboard's two files — one stylesheet, one
 * script — from the package, so installing the dashboard publishes
 * nothing, builds nothing, and reaches no CDN (docs/dashboard.md, "No
 * build step, anywhere"). Same-origin and nothing inline, so an
 * application's content-security policy need not loosen for it.
 *
 * Behind the same authorization as every other dashboard route: the
 * files reveal nothing, but "nothing reachable without the check" is a
 * simpler rule to hold than a list of exceptions.
 */
final class AssetController
{
    public function stylesheet(Request $request): Response
    {
        return $this->serve($request, 'dashboard.css', 'text/css; charset=utf-8');
    }

    public function script(Request $request): Response
    {
        return $this->serve($request, 'dashboard.js', 'text/javascript; charset=utf-8');
    }

    /**
     * @param  'dashboard.css'|'dashboard.js'  $file  Named here, never from the request: the route decides.
     */
    private function serve(Request $request, string $file, string $contentType): Response
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 3).'/resources/dist/'.$file);
        $etag = '"'.hash('xxh128', $contents).'"';

        $headers = [
            'Content-Type' => $contentType,
            'ETag' => $etag,
            // Private: it sits behind the authorization check, so a
            // shared cache must not serve it to the next visitor.
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response($contents, 200, $headers);
    }
}
