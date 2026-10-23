<?php

declare(strict_types=1);

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

    public function test_queue_configuration_defaults(): void
    {
        $this->assertNull(config('laraflow.queue.connection'));
        $this->assertNull(config('laraflow.queue.queue'));
        $this->assertSame(1, (int) config('laraflow.queue.tries'));
        $this->assertSame(0, (int) config('laraflow.queue.backoff'));
        $this->assertSame(60, (int) config('laraflow.queue.timeout'));
        $this->assertTrue((bool) config('laraflow.queue.after_commit'));
    }

    public function test_recovery_configuration_defaults(): void
    {
        $this->assertSame(100, (int) config('laraflow.recovery.limit'));
        $this->assertSame(3, (int) config('laraflow.recovery.max_attempts'));

        // Pending recovery is opt-in: `pending` is also the state of work
        // deliberately created-but-not-started via start().
        $this->assertNull(config('laraflow.recovery.pending_after'));
    }

    public function test_events_configuration_defaults(): void
    {
        $this->assertTrue((bool) config('laraflow.events.include_error_messages'));
    }

    public function test_default_queue_timeout_is_below_the_stale_claim_threshold(): void
    {
        // A worker timeout at or above stale_after would let a live job's
        // claim be reclaimed as stale. The shipped defaults must respect
        // the documented relationship.
        $this->assertLessThan(
            (int) config('laraflow.execution.stale_after'),
            (int) config('laraflow.queue.timeout'),
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
