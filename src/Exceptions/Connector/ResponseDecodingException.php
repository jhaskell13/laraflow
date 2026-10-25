<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Connector;

use Laraflow\Exceptions\ConnectorException;
use Laraflow\Integration\ConnectorResponse;
use Throwable;

/**
 * A response body could not be decoded as requested. The message names
 * the status and declared content type — never the body, which is the
 * provider's and may be large or sensitive.
 */
class ResponseDecodingException extends ConnectorException
{
    public static function invalidJson(ConnectorResponse $response, Throwable $previous): self
    {
        return new self(sprintf(
            'The response (status %d, content type %s) is not valid JSON.',
            $response->status(),
            $response->header('Content-Type') ?? 'undeclared',
        ), previous: $previous);
    }
}
