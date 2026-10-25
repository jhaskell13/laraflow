<?php

declare(strict_types=1);

namespace Laraflow\Auth;

use Laraflow\Integration\Credentials;

/**
 * Injects a connection's credentials into the transport options of an
 * outbound request.
 *
 * Receives the credentials rather than holding them: an authenticator
 * is built once with the connector, while credentials are resolved for
 * every request.
 */
interface Authenticator
{
    /**
     * @param  array<string, mixed>  $requestOptions
     * @return array<string, mixed>
     */
    public function authenticate(array $requestOptions, Credentials $credentials): array;
}
