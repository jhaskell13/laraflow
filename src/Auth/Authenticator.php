<?php

declare(strict_types=1);

namespace Laraflow\Auth;

interface Authenticator
{
    /**
     * Inject credentials into the transport options of an outbound request.
     *
     * @param  array<string, mixed>  $requestOptions
     * @return array<string, mixed>
     */
    public function authenticate(array $requestOptions = []): array;
}
