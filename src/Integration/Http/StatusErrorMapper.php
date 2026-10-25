<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Laraflow\Exceptions\Connector\AuthenticationFailedException;
use Laraflow\Exceptions\Connector\ClientErrorException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Exceptions\Connector\ResponseDecodingException;
use Laraflow\Exceptions\Connector\ServerErrorException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * The default mapper: by status. 401 and 403 are authentication
 * failures, 404 is not found, 429 is rate limited, any other 4xx a
 * client error, 5xx a server error; everything below 400 is not an
 * error. The provider's own description is taken from the usual JSON
 * fields when the body has them.
 *
 * Extend it for a provider with its own conventions and fall back to
 * parent::map() for the rest.
 */
class StatusErrorMapper implements ErrorMapper
{
    /**
     * JSON fields providers commonly put their explanation in.
     *
     * @var list<string>
     */
    protected const DETAIL_FIELDS = ['message', 'error_description', 'error.message', 'error', 'detail', 'title'];

    public function map(ConnectorRequest $request, ConnectorResponse $response, Connection $connection): ?RequestFailedException
    {
        $status = $response->status();

        if ($status < 400) {
            return null;
        }

        $class = match (true) {
            $status === 401, $status === 403 => AuthenticationFailedException::class,
            $status === 404 => NotFoundException::class,
            $status === 429 => RateLimitedException::class,
            $status < 500 => ClientErrorException::class,
            default => ServerErrorException::class,
        };

        return $class::fromResponse($request, $response, $connection, $this->detail($response));
    }

    /**
     * The provider's explanation, when the body is JSON and carries one.
     */
    protected function detail(ConnectorResponse $response): ?string
    {
        if (! $response->isJson()) {
            return null;
        }

        try {
            foreach (static::DETAIL_FIELDS as $field) {
                $value = $response->json($field);

                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        } catch (ResponseDecodingException) {
            // Declared JSON, was not: the status still classifies it.
        }

        return null;
    }
}
