<?php

declare(strict_types=1);

namespace Laraflow\Integration\Slack;

use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\Http\ErrorMapper;
use Laraflow\Integration\HttpConnector;
use Laraflow\Integration\Pagination\CursorPaginator;
use Laraflow\Integration\Pagination\Paginator;

/**
 * Slack's Web API.
 *
 * Built on the same public contracts an application's own connector
 * uses — HttpConnector, Authenticator, ErrorMapper, Paginator — and on
 * nothing internal. That is deliberate and enforced by test: if this
 * connector needed something a third party cannot reach, the extension
 * points would not be finished.
 *
 * It exercises what the GitHub connector does not: a provider that
 * reports failure inside a 200, cursor pagination whose result key
 * differs per endpoint, and methods that are mostly POSTs.
 *
 * @experimental Shipped so the connector contracts can be judged against a
 *               second real provider; its helper methods may change.
 */
class SlackConnector extends HttpConnector
{
    public const BASE_URL = 'https://slack.com/api';

    /**
     * A paginator for one listing method, naming where its results live.
     *
     * ```php
     * foreach ($slack->items($request, paginator: $slack->listing('members')) as $member) { ... }
     * ```
     */
    public function listing(string $itemsKey): Paginator
    {
        return new CursorPaginator(itemsPath: $itemsKey);
    }

    /**
     * A `GET` for a read method, with its arguments as the query.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function read(string $method, array $arguments = []): ConnectorRequest
    {
        return new ConnectorRequest('GET', '/'.ltrim($method, '/'), $arguments === [] ? [] : ['query' => $arguments]);
    }

    /**
     * A `POST` for a write method, with its arguments as a JSON body.
     *
     * Not marked idempotent: posting a message twice posts two
     * messages. Slack's own `Retry-After` path is unaffected — a 429 is
     * refused before processing and may always be repeated.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function write(string $method, array $arguments = []): ConnectorRequest
    {
        return new ConnectorRequest('POST', '/'.ltrim($method, '/'), ['json' => $arguments]);
    }

    protected function baseUrl(): ?string
    {
        return self::BASE_URL;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Accept' => 'application/json'];
    }

    /**
     * Slack's Web API is not versioned: methods are added and
     * deprecated individually, announced on its changelog. There is no
     * version to pin, so the connector tracks "current" and says so.
     */
    protected function apiVersion(): ?string
    {
        return null;
    }

    protected function authenticator(): Authenticator
    {
        return new BearerTokenAuthenticator('token', 'LARAFLOW_SLACK_TOKEN');
    }

    protected function errorMapper(): ErrorMapper
    {
        return new SlackErrorMapper;
    }

    /**
     * Slack pages everything by cursor, but the results live under a
     * different key per method — `members`, `channels`, `files`. The
     * connector's default reads the envelope; listing() names the key.
     */
    protected function paginator(): ?Paginator
    {
        return new CursorPaginator;
    }
}
