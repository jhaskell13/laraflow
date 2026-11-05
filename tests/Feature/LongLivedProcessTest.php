<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature;

use Illuminate\Support\Facades\Context;
use Laraflow\Support\ExecutionScope;
use Laraflow\Support\LoggingContext;
use Laraflow\Support\Secret;
use Laraflow\Tests\Support\ParentWorkflow;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\WorkflowRunner;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Under Octane, a queue worker, or Horizon, one PHP process serves many
 * requests and jobs. Whatever Laraflow leaves behind after one, the next
 * one sees: an execution still "current" would put the wrong ids on the
 * next request's log lines and the wrong tenant on its rate limit. These
 * hold the two places that could carry state: static properties, and
 * the per-run scope and log context.
 */
class LongLivedProcessTest extends TestCase
{
    /**
     * Static properties outlive every request in the process. The only
     * one allowed holds secrets weakly, keyed by the Secret object that
     * owns each value: it empties as those objects are collected.
     */
    private const STATIC_ALLOWED = [
        Secret::class.'::values',
    ];

    public function test_nothing_in_the_package_holds_mutable_static_state(): void
    {
        $found = [];

        foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $file) {
            $class = 'Laraflow\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class) && ! trait_exists($class)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                if ($property->isStatic() && $property->getDeclaringClass()->getName() === $class && ! $property->isReadOnly()) {
                    $found[] = $class.'::'.$property->getName();
                }
            }
        }

        $this->assertSame(self::STATIC_ALLOWED, $found, 'Static state survives into the next request of a long-lived process.');
    }

    public function test_a_failed_run_leaves_no_execution_current_and_nothing_in_the_log_context(): void
    {
        StepTwo::$failTimes = 99;

        try {
            $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);
            $this->fail('The step was told to fail.');
        } catch (RuntimeException) {
        }

        $this->assertClean();
    }

    public function test_a_failed_child_run_inline_leaves_neither_run_current(): void
    {
        StepTwo::$failTimes = 99;

        try {
            $this->app->make(WorkflowRunner::class)->run(new ParentWorkflow);
        } catch (RuntimeException) {
        }

        $this->assertClean();
    }

    public function test_the_dashboards_rules_live_in_the_container_not_in_the_process(): void
    {
        \Laraflow\Dashboard\Dashboard::auth(fn (): bool => true);

        $this->assertTrue($this->app->bound(\Laraflow\Dashboard\Dashboard::AUTH));

        // A fresh application — the next test, or the next Octane
        // worker boot — has no rule unless its own provider sets one.
        $this->refreshApplication();
        $this->assertFalse($this->app->bound(\Laraflow\Dashboard\Dashboard::AUTH));
    }

    private function assertClean(): void
    {
        $scope = $this->app->make(ExecutionScope::class);

        $this->assertFalse($scope->active(), 'An execution is still current after its run ended.');
        $this->assertNull($scope->tenantId());

        $leftover = array_filter(
            array_keys(Context::all()),
            fn (string $key): bool => str_starts_with($key, LoggingContext::PREFIX),
        );

        $this->assertSame([], array_values($leftover), 'Laraflow keys remain in the log context after the run.');
    }
}
