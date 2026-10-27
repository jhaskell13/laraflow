<?php

declare(strict_types=1);

namespace Laraflow\Tests;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Tests\Support\FixtureState;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Testbench declares this `Application|null` because it is null
     * before the application is created. By the time any test method
     * runs it never is, and treating it as nullable would put a `?->`
     * or an assertion in front of several hundred ordinary container
     * calls without making one of them safer.
     *
     * Narrowed here rather than in a PHPStan stub so the claim lives
     * with the suite that relies on it.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture steps keep static state (run counters, failure switches);
        // resetting centrally means no test class can forget and leak state
        // into another under randomized ordering.
        FixtureState::reset();
    }

    /**
     * The step at a position, asserted to exist.
     *
     * Reading `steps[0]->status` directly takes a property off a row
     * that may not be there; when it is not, the test dies with a PHP
     * error about null instead of saying which step was missing. Going
     * through here turns that into an ordinary assertion failure.
     */
    protected function stepAt(WorkflowExecutionModel $execution, int $position): StepExecutionModel
    {
        $step = $execution->steps[$position] ?? null;

        $this->assertInstanceOf(
            StepExecutionModel::class,
            $step,
            "Expected a step at position {$position}; the execution has {$execution->steps->count()}.",
        );

        return $step;
    }

    /**
     * `artisan()` for the cases that chain expectations onto it.
     *
     * It is typed `PendingCommand|int` because it returns the exit code
     * once the command has already been run; before that it returns the
     * pending command that `assertSuccessful()` and friends live on.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        $this->assertInstanceOf(PendingCommand::class, $pending, "[{$command}] has already run.");

        return $pending;
    }

    /**
     * The row at an index of a collection, asserted to exist. The same
     * reason as step(), for the collections that are not an execution's
     * steps — attempt history, query results.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $rows
     * @return TModel
     */
    protected function row(Collection $rows, int $index, string $what = 'row'): Model
    {
        $row = $rows[$index] ?? null;

        $this->assertNotNull($row, "Expected a {$what} at index {$index}; the collection has {$rows->count()}.");

        return $row;
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaraflowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // The schema has always declared cascading foreign keys, but
            // SQLite only enforces them when asked — without this, tests
            // asserting on cascade behaviour pass vacuously.
            'foreign_key_constraints' => true,
        ]);
    }
}
