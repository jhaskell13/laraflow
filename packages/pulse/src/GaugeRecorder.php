<?php

declare(strict_types=1);

namespace Laraflow\Pulse;

use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\SnapshotMetrics;
use Laraflow\Observability\Snapshots;
use Laravel\Pulse\Events\IsolatedBeat;
use Laravel\Pulse\Recorders\Concerns\Throttling;

/**
 * Records the snapshot's gauges from `pulse:check`'s heartbeat.
 *
 * The isolated beat, not the shared one: the snapshot describes the
 * whole installation, so one server should take it, not every server
 * running `pulse:check`. Throttled to the configured interval — each
 * take is a fixed handful of indexed counts, but there is no reason to
 * take it every second.
 */
final class GaugeRecorder
{
    use Throttling;

    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly PulseSink $sink,
        private readonly MetricCatalog $catalog,
        private readonly int $interval = 60,
    ) {}

    public function record(IsolatedBeat $event): void
    {
        $this->throttle($this->interval, $event, function (IsolatedBeat $event): void {
            (new SnapshotMetrics($this->sink, $this->catalog))->publish($this->snapshots->take($event->time->toDateTimeImmutable()));
        });
    }
}
