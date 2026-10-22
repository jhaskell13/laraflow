<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Closure;
use Laraflow\Exceptions\ConnectorNotRegisteredException;
use Laraflow\Exceptions\InvalidConnectorException;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 */
class ConnectorFactory
{
    /**
     * Factories are user code: their return value is checked at runtime.
     *
     * @var array<string, Closure(): mixed>
     */
    protected array $definitions = [];

    /**
     * @param  callable(): Connector  $factory
     */
    public function register(string $name, callable $factory): void
    {
        $this->definitions[$name] = $factory(...);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * @throws ConnectorNotRegisteredException
     * @throws InvalidConnectorException
     */
    public function make(string $name): Connector
    {
        if (! $this->has($name)) {
            throw ConnectorNotRegisteredException::named($name);
        }

        $connector = ($this->definitions[$name])();

        if (! $connector instanceof Connector) {
            throw InvalidConnectorException::forFactory($name, $connector);
        }

        return $connector;
    }
}
