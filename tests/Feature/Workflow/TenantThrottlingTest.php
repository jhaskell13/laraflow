<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Event;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\WorkflowExecutionDeferred;
use Laraflow\Exceptions\Workflow\TenantThrottledException;
use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\OneStepWorkflow;
use Laraflow\Tests\Support\PausedWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\Tenancy\TenantCeiling;
use Laraflow\Workflow\Tenancy\TenantCeilings;
use Laraflow\Workflow\Tenancy\TenantLimits;
use Laraflow\Workflow\Tenancy\TenantPolicy;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * docs/tenancy.md, enforced — and the roadmap's exit criterion: two
 * tenants share a worker pool and neither can starve the other under
 * load.
 */
class TenantThrottlingTest extends TestCase
{
    use RunsArtisanCommands;

    protected FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-11-04 09:00:00');
        $this->app->instance(ClockInterface::class, $this->clock);
    }

    /**
     * The fairness rule under test. A pool of four simulated workers
     * takes work in arrival order — the flooding tenant's whole backlog
     * first, then the other tenant's handful — and the flooding tenant
     * is held to two slots. The other tenant finishes within the same
     * number of rounds whether the flood is twenty executions or two
     * hundred: the ceiling, not the backlog, decides what it waits for.
     */
    public function test_two_tenants_share_a_pool_and_the_bounded_one_cannot_starve_the_other(): void
    {
        $this->ceilings(overrides: ['acme' => ['max_concurrent' => 2]]);

        $small = $this->roundsUntilGlobexFinishes(flood: 20);
        $large = $this->roundsUntilGlobexFinishes(flood: 200);

        $this->assertSame($small, $large, 'The bound does not grow with the flood: that is what a ceiling is for.');
        $this->assertLessThanOrEqual(3, $small, 'Four workers, two reserved from the flood: five executions clear in three rounds.');
    }

    public function test_the_concurrency_ceiling_counts_only_fresh_claims_on_running_work(): void
    {
        $this->ceilings(maxConcurrent: 1);

        $holding = $this->claim($this->pending('acme'));
        $this->assertTrue($holding->isRunning());

        // The slot is held: the next claim for the tenant is deferred.
        $this->assertDeferred($this->pending('acme'), TenantCeiling::Concurrency);

        // A stale claim is a dead worker's and holds nothing.
        WorkflowExecutionModel::query()->whereKey($holding->id)->update(['claimed_at' => $this->clock->now()->modify('-301 seconds')]);
        $this->assertTrue($this->claim($this->pending('acme'))->isRunning());

        // A parked execution holds nothing either.
        $parked = $this->app->make(WorkflowRunner::class)->start(new PausedWorkflow, [], new ExecutionOptions(tenantId: 'globex'));
        $this->ceilings(maxConcurrent: 1);
        $this->app->make(WorkflowRunner::class)->execute($parked->id, new PausedWorkflow);
        $this->assertTrue($this->repository()->find($parked->id)->isWaiting());
        $this->assertTrue($this->claim($this->pending('globex'))->isRunning(), 'Waiting is not holding a worker.');
    }

    public function test_a_refusal_records_the_deferral_and_nothing_else(): void
    {
        $this->ceilings(maxConcurrent: 1);
        $this->claim($this->pending('acme'));

        $id = $this->pending('acme');
        $before = $this->repository()->find($id);

        $exception = $this->assertDeferred($id, TenantCeiling::Concurrency);

        $after = $this->repository()->find($id);

        $this->assertTrue($after->isPending(), 'The status did not move.');
        $this->assertNull($after->claimToken);
        $this->assertNull($after->failedAt);
        $this->assertSame(1, $after->deferrals);
        $this->assertNotNull($after->deferredUntil);
        $this->assertEquals($exception->deferredUntil, $after->deferredUntil);
        $this->assertSame([], $this->repository()->attemptHistory($after), 'No attempt was recorded: this is not a failure.');
        $this->assertSame('acme', $exception->tenantId);
        $this->assertSame(1, $exception->limit);
        $this->assertSame(1, $exception->count);
        $this->assertGreaterThanOrEqual(15, $exception->suggestedRetryDelaySeconds($this->clock->now()));
        $this->assertSame($before->fruitlessClaims, $after->fruitlessClaims, 'A refused claim is not a claim.');

        // Deferred again: the count grows, the status still does not.
        $this->assertDeferred($id, TenantCeiling::Concurrency);
        $this->assertSame(2, $this->repository()->find($id)->deferrals);
    }

    public function test_the_first_claim_that_lands_clears_the_deferral(): void
    {
        $this->ceilings(maxConcurrent: 1);
        $holding = $this->claim($this->pending('acme'));

        $id = $this->pending('acme');
        $this->assertDeferred($id, TenantCeiling::Concurrency);

        $holding->markCompleted($this->clock->now());
        $this->repository()->release($holding);

        $claimed = $this->claim($id);

        $this->assertTrue($claimed->isRunning());
        $this->assertSame(0, $claimed->deferrals);
        $this->assertNull($claimed->deferredUntil, 'A finished execution never carries a stale note.');
    }

    public function test_the_rate_ceiling_bounds_claims_in_a_sliding_minute(): void
    {
        $this->ceilings(maxClaimsPerMinute: 2);

        $first = $this->claim($this->pending('acme'));
        $first->markCompleted($this->clock->now());
        $this->repository()->release($first);

        $second = $this->claim($this->pending('acme'));
        $second->markCompleted($this->clock->now());
        $this->repository()->release($second);

        // Nothing is running, and the third claim is still refused: the
        // ceiling is on churn, not occupancy.
        $exception = $this->assertDeferred($this->pending('acme'), TenantCeiling::Rate);
        $this->assertSame(2, $exception->limit);
        $this->assertSame(2, $exception->count);

        // Another tenant's minute is its own.
        $this->assertTrue($this->claim($this->pending('globex'))->isRunning());
    }

    public function test_an_execution_without_a_tenant_is_never_bounded(): void
    {
        $this->ceilings(maxConcurrent: 1, maxClaimsPerMinute: 1);

        $runner = $this->app->make(WorkflowRunner::class);

        $this->assertTrue($this->claim($runner->start(new OneStepWorkflow)->id)->isRunning());
        $this->assertTrue($this->claim($runner->start(new OneStepWorkflow)->id)->isRunning());
        $this->assertTrue($this->claim($runner->start(new OneStepWorkflow)->id)->isRunning(), 'No tenant, nothing to be bounded by.');
    }

    public function test_a_tenant_without_a_ceiling_is_unbounded_and_an_override_replaces_only_what_it_names(): void
    {
        $this->ceilings(maxConcurrent: 1, overrides: ['acme' => ['max_concurrent' => null], 'globex' => ['max_claims_per_minute' => 5]]);

        // acme lifted its concurrency ceiling by naming null.
        $this->claim($this->pending('acme'));
        $this->assertTrue($this->claim($this->pending('acme'))->isRunning());

        // globex named only a rate ceiling and keeps the default
        // concurrency ceiling of one.
        $this->claim($this->pending('globex'));
        $this->assertDeferred($this->pending('globex'), TenantCeiling::Concurrency);
    }

    public function test_a_custom_policy_is_consulted_on_every_claim(): void
    {
        $policy = new class implements TenantPolicy
        {
            /** @var list<string> */
            public array $asked = [];

            public function limitsFor(string $tenantId): TenantLimits
            {
                $this->asked[] = $tenantId;

                return $tenantId === 'trial' ? new TenantLimits(maxConcurrent: 1) : TenantLimits::none();
            }
        };

        $this->app->instance(TenantPolicy::class, $policy);
        $this->app->forgetInstance(TenantCeilings::class);
        $this->app->forgetInstance(ExecutionRepository::class);
        $this->app->forgetInstance(WorkflowRunner::class);

        $this->claim($this->pending('trial'));
        $this->assertDeferred($this->pending('trial'), TenantCeiling::Concurrency);

        $this->claim($this->pending('enterprise'));
        $this->assertTrue($this->claim($this->pending('enterprise'))->isRunning());

        $this->assertSame(['trial', 'trial', 'enterprise', 'enterprise'], $policy->asked, 'Asked per claim, so a plan change takes effect on the next one.');
    }

    public function test_the_queue_job_re_pushes_for_the_deferral_without_spending_a_try(): void
    {
        config()->set('queue.default', 'database');
        $this->ceilings(maxConcurrent: 1);
        $this->claim($this->pending('acme'));

        $id = $this->pending('acme');
        $job = new ExecuteWorkflowJob($id);

        $this->app->call([$job, 'handle']);

        $execution = $this->repository()->find($id);
        $this->assertTrue($execution->isPending(), 'Nothing ran.');
        $this->assertSame(1, $execution->deferrals);

        $queued = $this->app->make('db')->table('jobs')->first();
        $this->assertNotNull($queued, 'Re-pushed once, for later.');
        $this->assertSame(1, $this->app->make('db')->table('jobs')->count());
        $this->assertSame(
            $execution->deferredUntil?->getTimestamp(),
            (int) $queued->available_at,
            'Available exactly when the record says delivery is next tried.',
        );
        $this->assertSame(1, $job->tries, 'A deferral is not a queue retry.');
        $this->assertSame(0, $this->app->make('db')->table('failed_jobs')->count());
    }

    public function test_on_the_sync_driver_the_refusal_reaches_the_dispatchers_caller(): void
    {
        $this->ceilings(maxConcurrent: 1);
        $this->claim($this->pending('acme'));

        $this->expectException(TenantThrottledException::class);

        $this->app->call([new ExecuteWorkflowJob($this->pending('acme')), 'handle']);
    }

    public function test_the_pending_scan_skips_a_deferred_execution_until_its_timer_fires(): void
    {
        $this->ceilings(maxConcurrent: 1);
        $this->claim($this->pending('acme'));

        $id = $this->pending('acme');
        $this->assertDeferred($id, TenantCeiling::Concurrency);

        $this->clock->advance(3600);
        $cutoff = $this->clock->now();

        // Pretend time moved for created_at too: the scan wants old pendings.
        WorkflowExecutionModel::query()->whereKey($id)->update(['created_at' => $this->clock->now()->modify('-2 hours')]);

        $deferredUntil = $this->repository()->find($id)->deferredUntil;
        $this->assertNotNull($deferredUntil);

        $this->clock->set($deferredUntil->modify('-1 second'));
        $this->assertNotContains($id, array_map(fn (WorkflowExecution $e): int => $e->id, $this->repository()->pendingBefore($cutoff)), 'Held by its own timer, not the scan\'s.');

        $this->clock->set($deferredUntil->modify('+1 second'));
        $this->assertContains($id, array_map(fn (WorkflowExecution $e): int => $e->id, $this->repository()->pendingBefore($cutoff)), 'Timer fired: the scan may deliver it.');
    }

    public function test_the_deferral_is_announced_and_shown_everywhere_an_operator_looks(): void
    {
        Event::fake([WorkflowExecutionDeferred::class]);
        $this->app->forgetInstance(EventEmitter::class);
        $this->app->forgetInstance(WorkflowRunner::class);

        $this->ceilings(maxConcurrent: 1);
        $this->claim($this->pending('acme'));
        $id = $this->pending('acme');

        try {
            $this->app->make(WorkflowRunner::class)->execute($id, new OneStepWorkflow);
            $this->fail('The ceiling should have refused the claim.');
        } catch (TenantThrottledException) {
        }

        Event::assertDispatched(WorkflowExecutionDeferred::class, fn (WorkflowExecutionDeferred $event): bool => $event->executionId === $id
            && $event->tenantId === 'acme'
            && $event->ceiling === TenantCeiling::Concurrency
            && $event->limit === 1
            && $event->count === 1);

        ['output' => $output] = $this->callCommand('laraflow:show', ['execution' => (string) $id]);
        $this->assertStringContainsString('Deferred', $output);
        $this->assertStringContainsString('×1', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:execute', ['execution' => (string) $id]);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('at its concurrency ceiling', $output);
        $this->assertStringContainsString('Raise the ceiling', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:summary');
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('deferred by a tenant ceiling', $output);

        $deferred = $this->repository()->deferred(ExecutionFilter::all(), $this->clock->now());
        $this->assertSame([$id], array_map(fn (WorkflowExecution $e): int => $e->id, $deferred));
        $this->assertSame([], $this->repository()->deferred(new ExecutionFilter(tenantId: 'globex'), $this->clock->now()), 'Scoped, like every other number on the summary.');
    }

    /**
     * Four workers, arrival order, the flood first. Each round a free
     * worker takes the next job; a claim the ceiling turns away goes
     * to the back, as its deferral timer would send it; a claimed
     * execution holds its worker for the round and completes at the
     * start of the next. Returns the round in which globex's last
     * execution was claimed, and asserts the ceiling was never exceeded.
     */
    protected function roundsUntilGlobexFinishes(int $flood): int
    {
        WorkflowExecutionModel::query()->delete();

        $queue = [];

        for ($i = 0; $i < $flood; $i++) {
            $queue[] = $this->pending('acme');
        }

        $globex = [];

        for ($i = 0; $i < 5; $i++) {
            $queue[] = $globex[] = $this->pending('globex');
        }

        $busy = [];
        $claimedGlobex = 0;
        $round = 0;

        while ($claimedGlobex < 5) {
            $round++;
            $this->assertLessThan(20, $round, 'The other tenant is being starved.');

            foreach ($busy as $execution) {
                $execution->markCompleted($this->clock->now());
                $this->repository()->release($execution);
            }

            $busy = [];
            $free = 4;
            $back = [];

            while ($free > 0 && $queue !== []) {
                $id = array_shift($queue);

                try {
                    $busy[] = $this->claim($id);
                    $free--;

                    if (in_array($id, $globex, true)) {
                        $claimedGlobex++;
                    }
                } catch (TenantThrottledException) {
                    $back[] = $id;
                }
            }

            $this->assertLessThanOrEqual(
                2,
                WorkflowExecutionModel::query()->where('tenant_id', 'acme')->where('status', 'running')->count(),
                'The flooding tenant never holds more than its ceiling.',
            );

            $queue = array_merge($queue, $back);
        }

        return $round;
    }

    protected function pending(string $tenant): int
    {
        return $this->app->make(WorkflowRunner::class)->start(new OneStepWorkflow, [], new ExecutionOptions(tenantId: $tenant))->id;
    }

    protected function claim(int $id): WorkflowExecution
    {
        return $this->repository()->claim($id, bin2hex(random_bytes(8)), 300);
    }

    protected function assertDeferred(int $id, TenantCeiling $ceiling): TenantThrottledException
    {
        try {
            $this->claim($id);
        } catch (TenantThrottledException $exception) {
            $this->assertSame($ceiling, $exception->ceiling);

            return $exception;
        }

        $this->fail("Execution [{$id}] should have been deferred by the {$ceiling->value} ceiling.");
    }

    /**
     * @param  array<string, array<string, int|null>>  $overrides
     */
    protected function ceilings(?int $maxConcurrent = null, ?int $maxClaimsPerMinute = null, array $overrides = []): void
    {
        config()->set('laraflow.tenants.max_concurrent', $maxConcurrent);
        config()->set('laraflow.tenants.max_claims_per_minute', $maxClaimsPerMinute);
        config()->set('laraflow.tenants.overrides', $overrides);
    }

    protected function repository(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
