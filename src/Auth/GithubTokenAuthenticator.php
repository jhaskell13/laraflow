<?php

declare(strict_types=1);

namespace Laraflow\Auth;

use Laraflow\Exceptions\ConnectorException;

class GithubTokenAuthenticator implements Authenticator
{
    public function __construct(protected string $token)
    {
        if (trim($token) === '') {
            throw new ConnectorException(
                'The GitHub token is empty. Set LARAFLOW_GITHUB_TOKEN or laraflow.integrations.github.token.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $requestOptions
     * @return array<string, mixed>
     */
    public function authenticate(array $requestOptions = []): array
    {
        $requestOptions['headers']['Authorization'] = 'Bearer '.$this->token;

        return $requestOptions;
    }
}
