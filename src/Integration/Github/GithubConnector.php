<?php

declare(strict_types=1);

namespace Laraflow\Integration\Github;

use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Integration\Http\ErrorMapper;
use Laraflow\Integration\HttpConnector;
use Laraflow\Integration\Pagination\LinkHeaderPaginator;
use Laraflow\Integration\Pagination\Paginator;

/**
 * @experimental Prototype connector, kept experimental until the first release.
 */
class GithubConnector extends HttpConnector
{
    public const BASE_URL = 'https://api.github.com';

    protected function baseUrl(): ?string
    {
        return self::BASE_URL;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Accept' => 'application/vnd.github+json'];
    }

    protected function authenticator(): Authenticator
    {
        return new BearerTokenAuthenticator('token', 'LARAFLOW_GITHUB_TOKEN');
    }

    protected function errorMapper(): ErrorMapper
    {
        return new GithubErrorMapper;
    }

    protected function paginator(): ?Paginator
    {
        return new LinkHeaderPaginator;
    }
}
