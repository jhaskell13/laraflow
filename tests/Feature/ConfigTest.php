<?php

namespace Laraflow\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Laraflow\Core\LaraflowServiceProvider;

class ConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaraflowServiceProvider::class,
        ];
    }

    public function test_laraflow_configuration_is_available(): void
    {
        $this->assertSame(
            null,
            config('laraflow.integrations.github.token')
        );
    }

    public function test_github_token_can_be_configured(): void
    {
        config([
            'laraflow.integrations.github.token' => 'test-token',
        ]);

        $this->assertSame(
            'test-token',
            config('laraflow.integrations.github.token')
        );
    }
}