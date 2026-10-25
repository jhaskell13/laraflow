<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Integration\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    public function test_it_reads_settings_with_defaults(): void
    {
        $connection = new Connection('github', 'enterprise', ['base_url' => 'https://ghe.example']);

        $this->assertSame('https://ghe.example', $connection->get('base_url'));
        $this->assertSame(30, $connection->get('timeout', 30));
        $this->assertTrue($connection->has('base_url'));
        $this->assertFalse($connection->has('timeout'));
    }

    public function test_require_returns_a_present_setting(): void
    {
        $connection = new Connection('github', 'default', ['token' => 'abc']);

        $this->assertSame('abc', $connection->require('token'));
    }

    #[DataProvider('absentValues')]
    public function test_require_treats_null_and_blank_as_missing_and_names_the_path(mixed $value): void
    {
        $connection = new Connection('github', 'default', ['token' => $value]);

        try {
            $connection->require('token', 'LARAFLOW_GITHUB_TOKEN');
            $this->fail('Expected ConnectorConfigurationException.');
        } catch (ConnectorConfigurationException $exception) {
            $this->assertStringContainsString('laraflow.integrations.github.connections.default.token', $exception->getMessage());
            $this->assertStringContainsString('LARAFLOW_GITHUB_TOKEN', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function absentValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => ['   '],
        ];
    }

    public function test_require_without_an_environment_hint_still_names_the_path(): void
    {
        $connection = new Connection('crm', 'default');

        try {
            $connection->require('api_key');
            $this->fail('Expected ConnectorConfigurationException.');
        } catch (ConnectorConfigurationException $exception) {
            $this->assertSame('laraflow.integrations.crm.connections.default.api_key is not set.', $exception->getMessage());
        }
    }

    public function test_the_key_identifies_connector_and_connection(): void
    {
        $connection = new Connection('slack', 'acme');

        $this->assertSame('slack:acme', $connection->key());
        $this->assertSame('laraflow.integrations.slack.connections.acme', $connection->path());
    }
}
