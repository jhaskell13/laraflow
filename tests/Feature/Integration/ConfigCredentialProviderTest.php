<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Contracts\Config\Repository as Config;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Integration\ConfigCredentialProvider;
use Laraflow\Integration\Connection;
use Laraflow\Tests\TestCase;

class ConfigCredentialProviderTest extends TestCase
{
    public function test_it_reads_the_connections_credentials_array(): void
    {
        config(['laraflow.integrations.crm.connections.eu.credentials' => ['token' => 'secret-token', 'blank' => '', 'unset' => null]]);

        $credentials = $this->provider()->credentials(new Connection('crm', 'eu'));

        $this->assertSame('secret-token', $credentials->get('token')?->reveal());
        $this->assertSame(['token'], $credentials->keys(), 'Blank and null entries are dropped.');
        $this->assertSame('eu', $credentials->connection->name);
    }

    public function test_no_credentials_key_means_no_credentials(): void
    {
        $credentials = $this->provider()->credentials(new Connection('crm', 'missing'));

        $this->assertSame([], $credentials->keys());
    }

    public function test_a_change_to_the_repository_is_seen_by_the_next_call(): void
    {
        $provider = $this->provider();
        $connection = new Connection('crm', 'eu');

        config(['laraflow.integrations.crm.connections.eu.credentials' => ['token' => 'first']]);
        $first = $provider->credentials($connection)->get('token')?->reveal();

        config(['laraflow.integrations.crm.connections.eu.credentials' => ['token' => 'rotated']]);
        $second = $provider->credentials($connection)->get('token')?->reveal();

        $this->assertSame(['first', 'rotated'], [$first, $second]);
    }

    public function test_a_scalar_credentials_value_is_refused_by_path_without_echoing_it(): void
    {
        config(['laraflow.integrations.crm.connections.eu.credentials' => 'secret-token']);

        try {
            $this->provider()->credentials(new Connection('crm', 'eu'));
            $this->fail('A non-array credentials value should be refused.');
        } catch (ConnectorConfigurationException $e) {
            $this->assertStringContainsString('laraflow.integrations.crm.connections.eu.credentials must be an array', $e->getMessage());
            $this->assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    protected function provider(): ConfigCredentialProvider
    {
        return new ConfigCredentialProvider($this->app->make(Config::class));
    }
}
