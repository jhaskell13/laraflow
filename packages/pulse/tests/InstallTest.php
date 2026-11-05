<?php

declare(strict_types=1);

namespace Laraflow\Pulse\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Pulse\Livewire\Workflows;
use Laraflow\Pulse\PulseServiceProvider;
use Laraflow\Pulse\PulseSink;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Workflow\WorkflowRunner;
use Laravel\Pulse\Events\IsolatedBeat;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

/**
 * Off until enabled; enabled, a workflow's events reach Pulse, the
 * heartbeat records the gauges, and the card renders what was recorded.
 */
final class InstallTest extends TestCase
{
    public function test_installing_the_bridge_and_doing_nothing_else_changes_nothing(): void
    {
        $this->assertFalse(config('laraflow-pulse.enabled'));
        $this->assertFalse(Event::hasListeners(WorkflowExecutionStarted::class));
        $this->assertFalse($this->app->bound(PulseSink::class));
    }

    public function test_enabled_a_run_is_recorded_the_beat_records_gauges_and_the_card_shows_both(): void
    {
        config()->set('laraflow-pulse.enabled', true);
        $this->app->register(PulseServiceProvider::class, force: true);

        $runner = $this->app->make(WorkflowRunner::class);
        $runner->run(new TwoStepWorkflow);
        $runner->start(new TwoStepWorkflow);

        event(new IsolatedBeat(CarbonImmutable::now()));
        // Throttled: a second beat inside the interval records nothing new.
        event(new IsolatedBeat(CarbonImmutable::now()));
        Pulse::ingest();

        $finished = PulseSink::key(['workflow' => TwoStepWorkflow::class, 'outcome' => 'completed']);
        $this->assertSame(1.0, (float) DB::table('pulse_aggregates')->where(['type' => 'laraflow_executions_finished_total', 'key' => $finished, 'aggregate' => 'sum', 'period' => 60])->value('value'));

        $pending = PulseSink::key(['workflow' => TwoStepWorkflow::class, 'status' => 'pending']);
        $this->assertSame('1', DB::table('pulse_values')->where(['type' => 'laraflow_executions', 'key' => $pending])->value('value'));

        Livewire::withoutLazyLoading()->test(Workflows::class)
            ->assertSee('Laraflow')
            ->assertSee('TwoStepWorkflow')
            ->assertSee('unfinished');
    }
}
