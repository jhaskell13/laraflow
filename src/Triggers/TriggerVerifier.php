<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

use Illuminate\Http\Request;
use Laraflow\Exceptions\TriggerRefusedException;

/**
 * How a named trigger decides a provider's request is genuine. The
 * provider signs its own way — Stripe's header, GitHub's, a shared
 * token — and the application already knows how to check it; this is
 * where that check lives, resolved from the container so it can read
 * the provider's secret from wherever the application keeps it.
 *
 * Runs before anything is read from the database, exactly as the
 * signed endpoint's own verification does.
 */
interface TriggerVerifier
{
    /**
     * Return to accept; throw to refuse. The refusal is answered as the
     * same `401 {"status": "refused"}` every verification failure gets.
     *
     * @throws TriggerRefusedException
     */
    public function verify(Request $request): void;
}
