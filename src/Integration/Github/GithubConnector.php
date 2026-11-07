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
 * GitHub's REST API.
 *
 * Built on the same public contracts an application's own connector
 * uses — HttpConnector, Authenticator, ErrorMapper, Paginator — and on
 * nothing internal, which a test enforces: if this connector needed
 * something a third party cannot reach, the extension points would not
 * be finished.
 *
 * It pins the REST API version it was written against and sends it in
 * GitHub's own header, so a provider change is a version bump rather
 * than a surprise; and it pages through the RFC 8288 `Link` header,
 * whose next-page URL is absolute and therefore host-checked before it
 * is followed.
 *
 * The generic request, error, and paging machinery is the whole of it.
 * Provider-specific conveniences are an application's to add on top.
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
