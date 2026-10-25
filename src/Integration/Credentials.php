<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Support\Secret;
use SensitiveParameter;

/**
 * The secrets belonging to one connection, each wrapped in a Secret.
 *
 * Resolved by the CredentialProvider when a request is built — not when
 * the connector is constructed — so a rotated credential is used by the
 * next request with nothing rebuilt, and a cached connector instance
 * holds no credential at all.
 */
final readonly class Credentials
{
    /**
     * @var array<string, Secret>
     */
    private array $secrets;

    /**
     * @param  array<string, Secret|string|int|null>  $secrets  Blank and null entries are dropped.
     */
    public function __construct(
        public Connection $connection,
        #[SensitiveParameter] array $secrets = [],
    ) {
        $wrapped = [];

        foreach ($secrets as $key => $value) {
            $secret = Secret::from($value);

            if ($secret !== null) {
                $wrapped[(string) $key] = $secret;
            }
        }

        $this->secrets = $wrapped;
    }

    public static function none(Connection $connection): self
    {
        return new self($connection);
    }

    public function has(string $key): bool
    {
        return isset($this->secrets[$key]);
    }

    public function get(string $key): ?Secret
    {
        return $this->secrets[$key] ?? null;
    }

    /**
     * @param  string|null  $environmentVariable  Named in the error so the fix is one line away.
     *
     * @throws ConnectorConfigurationException
     */
    public function require(string $key, ?string $environmentVariable = null): Secret
    {
        return $this->secrets[$key] ?? throw ConnectorConfigurationException::missing(
            $this->connection,
            "credentials.{$key}",
            $environmentVariable,
        );
    }

    /**
     * The names of the credentials present — safe to display.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->secrets);
    }
}
