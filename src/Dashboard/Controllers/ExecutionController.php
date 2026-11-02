<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * @internal The executions pages: the list, and one execution in full.
 */
final class ExecutionController
{
    /**
     * The dashboard's front door sends the visitor to the list until
     * the overview exists.
     */
    public function home(): RedirectResponse
    {
        return new RedirectResponse(route('laraflow.executions'));
    }

    public function index(): Response
    {
        return new Response('Laraflow dashboard', 200, ['Content-Type' => 'text/plain']);
    }
}
