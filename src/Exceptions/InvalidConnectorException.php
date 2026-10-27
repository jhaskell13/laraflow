<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

use Laraflow\Integration\Connector;

class InvalidConnectorException extends ConnectorException
{
    public static function forClass(string $name, string $class): self
    {
        return new self(sprintf(
            'Connector [%s] names class %s, which does not exist or does not implement %s.',
            $name,
            $class,
            Connector::class,
        ));
    }

    public static function forFactory(string $name, mixed $returned): self
    {
        return new self(sprintf(
            'Connector factory [%s] must return an instance of %s, got %s.',
            $name,
            Connector::class,
            get_debug_type($returned),
        ));
    }
}
