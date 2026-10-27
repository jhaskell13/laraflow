<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Integration\Connection;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use stdClass;

/**
 * A connector that records how it was built and what it was asked.
 *
 * The stdClass dependency exists to prove the registry autowires a
 * connector's other constructor parameters while passing the
 * Connection by name.
 */
class RecordingConnector implements Connector
{
    /**
     * @var list<ConnectorRequest>
     */
    public array $requests = [];

    public function __construct(
        public readonly Connection $connection,
        public readonly stdClass $dependency = new stdClass,
    ) {}

    public function name(): string
    {
        return $this->connection->connector;
    }

    public function request(ConnectorRequest $request): ConnectorResponse
    {
        $this->requests[] = $request;

        return ConnectorResponse::fake(200, ['connection' => $this->connection->key()]);
    }
}
