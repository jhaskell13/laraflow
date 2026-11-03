<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Illuminate\Http\Request;
use Laraflow\Exceptions\TriggerRefusedException;
use Laraflow\Triggers\TriggerVerifier;

/**
 * A provider verifier that refuses everything: the request did not
 * carry the provider's signature.
 */
class RefusingVerifier implements TriggerVerifier
{
    public function verify(Request $request): void
    {
        throw TriggerRefusedException::invalidSignature('The provider signature header is missing.');
    }
}
