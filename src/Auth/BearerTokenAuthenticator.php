<?php

declare(strict_types=1);

namespace Laraflow\Auth;

use Laraflow\Integration\Credentials;

/**
 * `Authorization: Bearer <token>`, the token read from the named
 * credential on every request.
 */
class BearerTokenAuthenticator implements Authenticator
{
    /**
     * @param  string  $credential  The credential name to read, e.g. `token`.
     * @param  string|null  $environmentVariable  Named when the credential is missing.
     */
    public function __construct(
        protected string $credential = 'token',
        protected ?string $environmentVariable = null,
    ) {}

    /**
     * @param  array<string, mixed>  $requestOptions
     * @return array<string, mixed>
     */
    public function authenticate(array $requestOptions, Credentials $credentials): array
    {
        $token = $credentials->require($this->credential, $this->environmentVariable)->reveal();

        $requestOptions['headers']['Authorization'] = 'Bearer '.$token;

        return $requestOptions;
    }
}
