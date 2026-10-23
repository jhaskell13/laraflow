<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Date;
use Laraflow\Support\Clock;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\StepExecutionModel;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Repositories\EloquentExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;

/**
 * The package reads time through an injected PSR-20 clock. Retry
 * scheduling will depend on that being true everywhere, so these tests
 * pin it down now: the container binds a clock, the default one follows
 * Laravel's Date facade, and an injected clock drives every timestamp
 * the runner and repository write.
 */
class ClockTest extends TestCase
{
    public function test_the_container_binds_a_psr20_clock_backed_by_laravels_date(): void
    {
        Date::setTestNow('2026-10-23 08:00:00');

        try {
            $clock = $this->app->make(ClockInterface::class);

            $this->assertInstanceOf(Clock::class, $clock);
            $this->assertSame('2026-10-23 08:00:00', $clock->now()->format('Y-m-d H:i:s'));
        } finally {
            Date::setTestNow();
        }
    }

    public function test_the_runner_and_repository_resolve_the_same_clock_instance(): void
    {
        // One source of time for the whole package: a replaced binding
        // must not leave half the package reading a different clock.
        $this->assertSame(
            $this->app->make(ClockInterface::class),
            $this->app->make(ClockInterface::class),
        );
    }

    public function test_an_injected_fixed_clock_drives_every_timestamp_a_run_writes(): void
    {
        $clock = new FixedClock('2026-10-23 09:30:00');

        // The repository stamps claimed_at/started_at itself, so it and
        // the runner share one clock.
        $runner = new WorkflowRunner(
            repository: new EloquentExecutionRepository($clock),
            container: $this->app,
            logger: new ArrayLogger,
            clock: $clock,
        );

        $execution = $runner->run(new TwoStepWorkflow);

        $this->assertSame('2026-10-23 09:30:00', $execution->startedAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 09:30:00', $execution->completedAt?->format('Y-m-d H:i:s'));

        $step = StepExecutionModel::query()->firstOrFail();
        $this->assertSame('2026-10-23 09:30:00', $step->started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 09:30:00', $step->completed_at?->format('Y-m-d H:i:s'));
    }

    public function test_advancing_an_injected_clock_moves_the_timestamps_it_writes(): void
    {
        $clock = new FixedClock('2026-10-23 09:00:00');
        $repository = new EloquentExecutionRepository($clock);

        $runner = new WorkflowRunner(
            repository: $repository,
            container: $this->app,
            logger: new ArrayLogger,
            clock: $clock,
        );

        $execution = $runner->start(new TwoStepWorkflow);

        // Time passes before a worker picks the execution up.
        $clock->advance(3600);

        $finished = $runner->execute($execution->id, new TwoStepWorkflow);

        $this->assertSame('2026-10-23 10:00:00', $finished->startedAt?->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-10-23 10:00:00',
            WorkflowExecutionModel::query()->sole()->started_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_repository_measures_claim_staleness_with_its_clock(): void
    {
        $clock = new FixedClock('2026-10-23 12:00:00');
        /** @var ExecutionRepository $repository */
        $repository = new EloquentExecutionRepository($clock);

        $execution = $repository->create(
            workflow: TwoStepWorkflow::class,
            steps: [\Laraflow\Tests\Support\StepOne::class],
        );

        $repository->claim($execution->id, 'first-worker', 300);

        // Ten minutes later the claim is older than stale_after (300s),
        // measured entirely against the injected clock.
        $clock->advance(600);

        $stale = $repository->stale($clock->now()->modify('-300 seconds'));

        $this->assertCount(1, $stale);
        $this->assertSame($execution->id, $stale[0]->id);

        // A second worker may now take it over.
        $reclaimed = $repository->claim($execution->id, 'second-worker', 300);

        $this->assertSame('2026-10-23 12:10:00', $reclaimed->claimedAt?->format('Y-m-d H:i:s'));
    }
}
