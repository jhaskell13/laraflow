<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Connector;

use Laraflow\Exceptions\ConnectorException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * The provider answered, and the answer was an error.
 *
 * Carries the response so the application can inspect it. The message
 * is built from the *unauthenticated* request — method and path, never
 * the query string, never the options — so nothing an authenticator
 * added can reach a log, an event, or the execution record.
 */
class RequestFailedException extends ConnectorException
{
    final public function __construct(
        string $message,
        public readonly ConnectorResponse $response,
    ) {
        parent::__construct($message);
    }

    /**
     * The exception for a response, the subclass chosen by status.
     *
     * @param  string|null  $detail  The provider's own description, if the mapper found one.
     */
    public static function fromResponse(
        ConnectorRequest $request,
        ConnectorResponse $response,
        Connection $connection,
        ?string $detail = null,
    ): static {
        $message = sprintf(
            'Connector [%s] received %d for %s %s%s',
            $connection->key(),
            $response->status(),
            strtoupper($request->method),
            self::pathOf($request->uri),
            $detail === null || trim($detail) === '' ? '.' : ': '.self::truncate(trim($detail)),
        );

        return new static($message, $response);
    }

    /**
     * A generic exception for an error status on a response that went
     * through no mapper.
     */
    public static function forStatus(ConnectorResponse $response): self
    {
        return new self("The request failed with status {$response->status()}.", $response);
    }

    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * The path alone. A query string may carry a credential
     * (`?access_token=`), and the message reaches places credentials
     * must not.
     */
    protected static function pathOf(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    protected static function truncate(string $detail): string
    {
        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;

        return mb_strlen($detail) > 200 ? mb_substr($detail, 0, 199).'…' : $detail;
    }
}
