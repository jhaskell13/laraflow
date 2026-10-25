<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Integration\HttpConnector;

/**
 * The smallest HttpConnector: a base URL and nothing else, so tests can
 * see what the base class does on its own.
 */
class FakeHttpConnector extends HttpConnector
{
    protected function baseUrl(): ?string
    {
        return 'https://crm.example/api';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Accept' => 'application/json', 'X-Client' => 'laraflow'];
    }
}
