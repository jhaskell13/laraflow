<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use Illuminate\Contracts\Config\Repository as Config;
use Laraflow\Exceptions\ConnectorConfigurationException;

/**
 * Default CredentialProvider: the `credentials` array of the connection's
 * configuration, read from the repository on every call so that a
 * change there is seen by the next request.
 *
 * @internal The default binding. Applications replace it by binding their
 *           own CredentialProvider; nothing should depend on this class.
 */
class ConfigCredentialProvider implements CredentialProvider
{
    public function __construct(protected Config $config) {}

    public function credentials(Connection $connection): Credentials
    {
        $credentials = $this->config->get("{$connection->path()}.credentials");

        if ($credentials === null) {
            return Credentials::none($connection);
        }

        if (! is_array($credentials)) {
            throw new ConnectorConfigurationException(
                "{$connection->path()}.credentials must be an array of credential name => value."
            );
        }

        return new Credentials($connection, $credentials);
    }
}
