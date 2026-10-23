<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature;

use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\Policy\PolicyResolver;

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
        $this->assertSame(3, (int) config('laraflow.recovery.abandon_after_attempts'));

        // Pending recovery is opt-in: `pending` is also the state of work
        // deliberately created-but-not-started via start().
        $this->assertNull(config('laraflow.recovery.pending_after'));
    }

    public function test_retry_configuration_defaults_disable_retries(): void
    {
        // max_attempts counts the first attempt, so 1 means nothing is
        // ever retried automatically. Retries are opt-in.
        $this->assertSame(1, (int) config('laraflow.retry.max_attempts'));
        $this->assertSame(5, (int) config('laraflow.retry.base_delay'));
        $this->assertSame(2.0, (float) config('laraflow.retry.multiplier'));
        $this->assertSame(300, (int) config('laraflow.retry.max_delay'));
        $this->assertSame(0.2, (float) config('laraflow.retry.jitter'));

        // The fast path ships on: punctual retries out of the box, with
        // the recovery scan as the safety net either way.
        $this->assertTrue((bool) config('laraflow.retry.dispatch_delayed'));
    }

    public function test_no_step_deadline_is_configured_by_default(): void
    {
        $this->assertNull(config('laraflow.timeout.step'));
    }

    public function test_the_resolver_is_built_from_configuration(): void
    {
        config([
            'laraflow.retry.max_attempts' => 4,
            'laraflow.retry.base_delay' => 11,
            'laraflow.retry.multiplier' => 3.0,
            'laraflow.retry.max_delay' => 900,
            'laraflow.retry.jitter' => 0.0,
            'laraflow.timeout.step' => 45,
        ]);

        $this->app->forgetInstance(PolicyResolver::class);

        $policy = $this->app->make(PolicyResolver::class)->forStep(
            new StepOne,
            new TwoStepWorkflow,
        );

        $this->assertSame(4, $policy->maxAttempts);
        $this->assertSame(11, $policy->baseDelaySeconds);
        $this->assertSame(3.0, $policy->multiplier);
        $this->assertSame(900, $policy->maxDelaySeconds);
        $this->assertSame(45, $policy->timeoutSeconds);
        $this->assertSame(33, $policy->delaySeconds(2), '11 * 3^1, no jitter.');
    }

    public function test_blank_environment_variables_fall_back_to_the_documented_defaults(): void
    {
        // A declared-but-empty variable (`LARAFLOW_RETRY_MAX_ATTEMPTS=`,
        // i.e. a stray blank line in .env) arrives as ''. Casting that
        // would yield 0, which StepPolicy rightly rejects — turning a
        // typo into an application that cannot resolve the runner at
        // all. Absent means "use the default".
        config([
            'laraflow.retry.max_attempts' => '',
            'laraflow.retry.base_delay' => '',
            'laraflow.retry.multiplier' => '',
            'laraflow.retry.max_delay' => '',
            'laraflow.retry.jitter' => '',
            'laraflow.timeout.step' => '',
        ]);

        $this->app->forgetInstance(PolicyResolver::class);

        $policy = $this->app->make(PolicyResolver::class)->forStep(new StepOne, new TwoStepWorkflow);

        $this->assertSame(1, $policy->maxAttempts);
        $this->assertSame(5, $policy->baseDelaySeconds);
        $this->assertSame(2.0, $policy->multiplier);
        $this->assertSame(300, $policy->maxDelaySeconds);
        $this->assertSame(0.2, $policy->jitter);
        $this->assertNull($policy->timeoutSeconds);
    }

    public function test_the_shipped_defaults_order_step_deadline_below_worker_timeout_below_stale_after(): void
    {
        // A step deadline above the worker timeout could never be
        // reached before the worker is killed; a worker timeout at or
        // above stale_after lets a live job's claim be reclaimed.
        $stepTimeout = config('laraflow.timeout.step');

        if ($stepTimeout !== null) {
            $this->assertLessThan((int) config('laraflow.queue.timeout'), (int) $stepTimeout);
        }

        $this->assertLessThan(
            (int) config('laraflow.execution.stale_after'),
            (int) config('laraflow.queue.timeout'),
        );
    }

    public function test_events_configuration_defaults(): void
    {
        $this->assertTrue((bool) config('laraflow.events.include_error_messages'));
    }

    public function test_error_redaction_is_off_by_default(): void
    {
        $this->assertNull(config('laraflow.errors.redactor'));
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
