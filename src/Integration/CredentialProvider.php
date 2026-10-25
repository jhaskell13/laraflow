<?php

declare(strict_types=1);

namespace Laraflow\Integration;

/**
 * Where a connection's secrets come from.
 *
 * Called when a request is built, every time. The default reads
 * `laraflow.integrations.{connector}.connections.{name}.credentials`;
 * an application binds its own to read a vault, a database row, or a
 * per-tenant store — and rotation is then a matter of changing what
 * the store returns.
 */
interface CredentialProvider
{
    public function credentials(Connection $connection): Credentials;
}
