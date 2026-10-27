<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

use Laraflow\Integration\Connection;

/**
 * The `laraflow.integrations` configuration cannot be used as written.
 *
 * Every message names the configuration path involved, because the
 * person reading it is in a `.env` or a published config file and
 * needs to know which line to change.
 */
class ConnectorConfigurationException extends ConnectorException
{
    public static function invalidName(string $name, string $kind): self
    {
        return new self(sprintf(
            'A %s name must be a non-empty string without whitespace or control characters; got %s.',
            $kind,
            var_export($name, true),
        ));
    }

    public static function missing(Connection $connection, string $key, ?string $environmentVariable): self
    {
        $message = "{$connection->path()}.{$key} is not set.";

        if ($environmentVariable !== null) {
            $message .= " Set {$environmentVariable} or configure it directly.";
        }

        return new self($message);
    }

    /**
     * @param  list<string>  $recognised
     */
    public static function unexpectedKey(string $connector, string $key, array $recognised, string $defaultConnection): self
    {
        return new self(sprintf(
            'laraflow.integrations.%s.%s is not a recognised key. A connector section holds %s; '
            .'connection settings such as `%s` belong under laraflow.integrations.%s.connections.%s.',
            $connector,
            $key,
            implode(', ', array_map(fn (string $k): string => "`{$k}`", $recognised)),
            $key,
            $connector,
            $defaultConnection,
        ));
    }

    public static function malformedConnections(string $connector): self
    {
        return new self(
            "laraflow.integrations.{$connector}.connections must be an array of connection name => settings array."
        );
    }
}
