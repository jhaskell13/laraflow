<?php

namespace Laraflow\Auth;

class GithubTokenAuthenticator implements Authenticator
{
    public function __construct(protected string $token) {}

    public function authenticate(array $requestOptions = []): array
    {
        $requestOptions['headers']['Authorization'] = 'Bearer ' . $this->token;

        return $requestOptions;
    }
}