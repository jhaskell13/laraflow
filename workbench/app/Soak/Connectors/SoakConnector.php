<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Connectors;

use Laraflow\Integration\HttpConnector;
use Laraflow\Integration\Pagination\PagePaginator;
use Laraflow\Integration\Pagination\Paginator;

/**
 * The connector to the stub provider (workbench/soak/stub-server.php):
 * the base URL comes from the connection, since the orchestrator picks
 * the port; the bearer token is the sentinel the logs are scanned for.
 */
final class SoakConnector extends HttpConnector
{
    protected function baseUrl(): ?string
    {
        return null;
    }

    protected function headers(): array
    {
        return ['Accept' => 'application/json'];
    }

    protected function paginator(): Paginator
    {
        return new PagePaginator('page', 1, 'items');
    }
}
