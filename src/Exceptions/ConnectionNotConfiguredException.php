<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

/**
 * A connector was asked for a connection its configuration does not
 * declare.
 */
class ConnectionNotConfiguredException extends ConnectorException
{
    /**
     * @param  list<string>  $configured
     */
    public static function named(string $connector, string $connection, array $configured): self
    {
        $known = $configured === []
            ? 'No connections are configured for it'
            : 'Configured connections: '.implode(', ', $configured);

        return new self(
            "Connector [{$connector}] has no connection named [{$connection}]. {$known}."
            ." Declare it under laraflow.integrations.{$connector}.connections.{$connection}."
        );
    }
}
