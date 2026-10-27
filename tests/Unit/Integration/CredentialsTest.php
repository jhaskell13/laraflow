<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Credentials;
use Laraflow\Support\Secret;
use PHPUnit\Framework\TestCase;

class CredentialsTest extends TestCase
{
    public function test_values_are_wrapped_and_blank_ones_dropped(): void
    {
        $credentials = new Credentials($this->connection(), [
            'token' => 'ghp_x',
            'secret' => new Secret('whsec_y'),
            'blank' => '',
            'absent' => null,
        ]);

        $this->assertSame(['token', 'secret'], $credentials->keys());
        $this->assertTrue($credentials->has('token'));
        $this->assertFalse($credentials->has('blank'));
        $this->assertSame('ghp_x', $credentials->get('token')?->reveal());
        $this->assertSame('whsec_y', $credentials->require('secret')->reveal());
        $this->assertNull($credentials->get('absent'));
    }

    public function test_a_missing_credential_names_its_configuration_path(): void
    {
        $credentials = Credentials::none($this->connection());

        try {
            $credentials->require('token', 'LARAFLOW_GITHUB_TOKEN');
            $this->fail('Expected ConnectorConfigurationException.');
        } catch (ConnectorConfigurationException $exception) {
            $this->assertStringContainsString(
                'laraflow.integrations.github.connections.default.credentials.token is not set',
                $exception->getMessage(),
            );
            $this->assertStringContainsString('LARAFLOW_GITHUB_TOKEN', $exception->getMessage());
        }
    }

    public function test_rendering_the_bag_reveals_nothing(): void
    {
        $credentials = new Credentials($this->connection(), ['token' => 'ghp_x']);

        foreach ([print_r($credentials, true), var_export($credentials, true), serialize($credentials)] as $rendered) {
            $this->assertStringNotContainsString('ghp_x', $rendered);
        }
    }

    protected function connection(): Connection
    {
        return new Connection('github', 'default');
    }
}
