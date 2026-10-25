<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Integration\Connection;
use Laraflow\Integration\CredentialProvider;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use Laraflow\Integration\Http\TransportPipeline;
use Laraflow\Integration\HttpConnector;

/**
 * Captures its token at construction, the way the Milestone 0 prototype
 * did. Exists so the published contract harness can be shown to catch
 * it rather than only to pass.
 */
class LeakyConnector extends HttpConnector
{
    protected string $token;

    public function __construct(
        Connection $connection,
        CredentialProvider $credentials,
        HttpTransport $transport,
        HttpOptions $defaults,
        TransportPipeline $pipeline,
    ) {
        parent::__construct($connection, $credentials, $transport, $defaults, $pipeline);

        $this->token = $credentials->credentials($connection)->require('token')->reveal();
    }

    protected function baseUrl(): ?string
    {
        return 'https://leaky.example';
    }
}
