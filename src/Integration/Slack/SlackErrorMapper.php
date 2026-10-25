<?php

declare(strict_types=1);

namespace Laraflow\Integration\Slack;

use Laraflow\Exceptions\Connector\AuthenticationFailedException;
use Laraflow\Exceptions\Connector\ClientErrorException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\StatusErrorMapper;

/**
 * Slack answers `200 OK` with `{"ok": false, "error": "..."}` for most
 * failures, so status alone would call every one of them a success.
 *
 * The error codes are grouped into the same types any other connector
 * produces, so an application can catch AuthenticationFailedException
 * without knowing which provider raised it.
 *
 * @experimental With the connector it belongs to.
 */
class SlackErrorMapper extends StatusErrorMapper
{
    /**
     * @var list<string>
     */
    protected const AUTHENTICATION = [
        'invalid_auth', 'not_authed', 'account_inactive', 'token_revoked',
        'token_expired', 'missing_scope', 'not_allowed_token_type', 'no_permission',
    ];

    /**
     * @var list<string>
     */
    protected const NOT_FOUND = [
        'channel_not_found', 'user_not_found', 'users_not_found', 'message_not_found',
        'file_not_found', 'team_not_found', 'thread_not_found',
    ];

    public function map(ConnectorRequest $request, ConnectorResponse $response, Connection $connection): ?RequestFailedException
    {
        // A non-2xx status is Slack's transport layer talking (429, 5xx);
        // the status mapper already knows what those mean.
        if ($response->status() < 200 || $response->status() >= 300) {
            return parent::map($request, $response, $connection);
        }

        $body = $response->json();

        // Not every Slack endpoint answers with an envelope (file
        // downloads, and anything behind a proxy that rewrote it).
        if (! is_array($body) || ! array_key_exists('ok', $body) || $body['ok'] !== false) {
            return null;
        }

        $code = is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';

        $class = match (true) {
            in_array($code, self::AUTHENTICATION, true) => AuthenticationFailedException::class,
            in_array($code, self::NOT_FOUND, true) => NotFoundException::class,
            $code === 'ratelimited' || $code === 'rate_limited' => RateLimitedException::class,
            default => ClientErrorException::class,
        };

        return $class::fromResponse($request, $response, $connection, $this->describe($response, $code));
    }

    /**
     * Slack's `error` code, plus whatever `response_metadata.messages`
     * explains, which is where its validation detail lives.
     */
    protected function describe(ConnectorResponse $response, string $code): string
    {
        $messages = $response->json('response_metadata.messages');

        if (is_array($messages) && $messages !== []) {
            $first = $messages[0] ?? null;

            if (is_string($first) && trim($first) !== '') {
                return "{$code} ({$first})";
            }
        }

        return $code;
    }
}
