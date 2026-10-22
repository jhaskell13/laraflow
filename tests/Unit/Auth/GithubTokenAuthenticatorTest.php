<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Auth;

use Laraflow\Auth\GithubTokenAuthenticator;
use Laraflow\Exceptions\ConnectorException;
use PHPUnit\Framework\TestCase;

class GithubTokenAuthenticatorTest extends TestCase
{
    public function test_it_adds_a_bearer_token(): void
    {
        $authenticator = new GithubTokenAuthenticator('test-token');

        $options = $authenticator->authenticate();

        $this->assertSame('Bearer test-token', $options['headers']['Authorization']);
    }

    public function test_it_preserves_existing_request_options(): void
    {
        $authenticator = new GithubTokenAuthenticator('test-token');

        $options = $authenticator->authenticate([
            'headers' => ['Accept' => 'application/json'],
            'query' => ['page' => 2],
        ]);

        $this->assertSame('application/json', $options['headers']['Accept']);
        $this->assertSame('Bearer test-token', $options['headers']['Authorization']);
        $this->assertSame(['page' => 2], $options['query']);
    }

    public function test_an_empty_token_is_rejected_up_front(): void
    {
        $this->expectException(ConnectorException::class);

        new GithubTokenAuthenticator('   ');
    }
}
