<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

final class ConnectorNotRegisteredException extends ConnectorException
{
    public static function named(string $name): self
    {
        return new self("Connector [{$name}] is not registered.");
    }
}
