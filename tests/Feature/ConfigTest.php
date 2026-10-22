<?php

namespace Laraflow\Tests\Feature;

use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_package_configuration_is_merged_with_defaults(): void
    {
        $this->assertIsArray(config('laraflow.integrations'));
        $this->assertArrayHasKey('github', config('laraflow.integrations'));
        $this->assertTrue(config('laraflow.migrations.load'));
        $this->assertNull(config('laraflow.integrations.github.token'));
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

    public function test_config_and_migrations_are_publishable(): void
    {
        $paths = LaraflowServiceProvider::pathsToPublish(
            LaraflowServiceProvider::class,
            'laraflow-config'
        );

        $this->assertCount(1, $paths);
        $this->assertSame(config_path('laraflow.php'), array_values($paths)[0]);

        $paths = LaraflowServiceProvider::pathsToPublish(
            LaraflowServiceProvider::class,
            'laraflow-migrations'
        );

        $this->assertCount(1, $paths);
        $this->assertSame(database_path('migrations'), array_values($paths)[0]);
    }
}
