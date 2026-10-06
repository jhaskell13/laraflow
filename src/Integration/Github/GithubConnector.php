<?php

namespace Laraflow\Integration\Github;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laraflow\Auth\Authenticator;
use Laraflow\Integration\Connector;

class GithubConnector implements Connector
{
    public function __construct(protected Authenticator $authenticator) {}

    public function name(): string
    {
        return 'github';
    }

    public function request(
        string $method,
        string $uri,
        array $options = []
    ): Response {
        $options = $this->authenticator->authenticate($options);
        
        return Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->send($method, $uri, $options);
    }
}