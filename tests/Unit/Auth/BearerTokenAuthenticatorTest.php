<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Auth;

use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Credentials;
use PHPUnit\Framework\TestCase;

class BearerTokenAuthenticatorTest extends TestCase
{
    public function test_it_adds_a_bearer_token_from_the_named_credential(): void
    {
        $authenticator = new BearerTokenAuthenticator('api_key');

        $options = $authenticator->authenticate([], $this->credentials(['api_key' => 'test-token']));

        $this->assertSame('Bearer test-token', $options['headers']['Authorization']);
    }

    public function test_it_preserves_existing_request_options(): void
    {
        $authenticator = new BearerTokenAuthenticator;

        $options = $authenticator->authenticate([
            'headers' => ['Accept' => 'application/json'],
            'query' => ['page' => 2],
        ], $this->credentials(['token' => 'test-token']));

        $this->assertSame('application/json', $options['headers']['Accept']);
        $this->assertSame('Bearer test-token', $options['headers']['Authorization']);
        $this->assertSame(['page' => 2], $options['query']);
    }

    public function test_a_missing_token_is_refused_naming_the_path_and_variable(): void
    {
        $authenticator = new BearerTokenAuthenticator('token', 'LARAFLOW_GITHUB_TOKEN');

        try {
            $authenticator->authenticate([], $this->credentials([]));
            $this->fail('Expected ConnectorConfigurationException.');
        } catch (ConnectorConfigurationException $exception) {
            $this->assertStringContainsString('connections.default.credentials.token', $exception->getMessage());
            $this->assertStringContainsString('LARAFLOW_GITHUB_TOKEN', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, string>  $secrets
     */
    protected function credentials(array $secrets): Credentials
    {
        return new Credentials(new Connection('github', 'default'), $secrets);
    }
}
