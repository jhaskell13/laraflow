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

    /**
     * The REST API version this connector is written and tested against.
     * GitHub selects it per request by header; a connection may pin
     * another with `api_version`.
     */
    public const API_VERSION = '2022-11-28';

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

    protected function apiVersion(): ?string
    {
        return self::API_VERSION;
    }

    protected function apiVersionHeader(): ?string
    {
        return 'X-GitHub-Api-Version';
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
