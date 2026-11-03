<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Illuminate\Http\Request;
use Laraflow\Triggers\TriggerVerifier;

/**
 * A provider verifier that accepts everything and counts what it saw —
 * standing in for the Stripe or GitHub check an application writes.
 */
class AcceptingVerifier implements TriggerVerifier
{
    public static int $verified = 0;

    public function verify(Request $request): void
    {
        static::$verified++;
    }
}
