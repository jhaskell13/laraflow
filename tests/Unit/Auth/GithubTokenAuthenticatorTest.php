<?php

use PHPUnit\Framework\TestCase;
use Laraflow\Auth\GithubTokenAuthenticator;

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
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $this->assertSame('application/json', $options['headers']['Accept']);
        $this->assertSame('Bearer test-token', $options['headers']['Authorization']);
    }
}