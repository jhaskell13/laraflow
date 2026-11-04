<?php

declare(strict_types=1);

namespace Laraflow\Testing;

use DateTimeImmutable;
use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Events\ConnectorRequestSent;
use Laraflow\Events\TriggerAccepted;
use Laraflow\Events\TriggerRefused;
use Laraflow\Events\WorkflowCompensated;
use Laraflow\Events\WorkflowCompensationStarted;
use Laraflow\Events\WorkflowExecutionCancelled;
use Laraflow\Events\WorkflowExecutionCompleted;
use Laraflow\Events\WorkflowExecutionDeferred;
use Laraflow\Events\WorkflowExecutionDispatched;
use Laraflow\Events\WorkflowExecutionFailed;
use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Events\WorkflowExecutionSignalled;
use Laraflow\Events\WorkflowExecutionStarted;
use Laraflow\Events\WorkflowFanOutStarted;
use Laraflow\Events\WorkflowPlanMigrated;
use Laraflow\Events\WorkflowScheduleSkipped;
use Laraflow\Events\WorkflowScheduleTicked;
use Laraflow\Events\WorkflowStepCompleted;
use Laraflow\Events\WorkflowStepFailed;
use Laraflow\Events\WorkflowStepRetryScheduled;
use Laraflow\Events\WorkflowStepSkipped;
use Laraflow\Events\WorkflowStepWaiting;
use Laraflow\Observability\Metrics\EventMetrics;
use Laraflow\Observability\Metrics\MetricCatalog;
use Laraflow\Observability\Metrics\MetricsSink;
use Laraflow\Observability\Metrics\SnapshotMetrics;
use Laraflow\Observability\MetricsSnapshot;
use Laraflow\Triggers\TriggerRefusalReason;
use Laraflow\Workflow\RecoveryReason;
use Laraflow\Workflow\Tenancy\TenantCeiling;
use PHPUnit\Framework\Assert;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * The conformance kit every metrics bridge is held to (docs/metrics.md,
 * "What every bridge must do"). A plain object, like ConnectorContract:
 * it collides with nothing in the host test and works from PHPUnit,
 * Pest, or Testbench.
 *
 * A bridge test replays every event and a snapshot through the real
 * sink, reads back what the backend now exposes — the scrape text, the
 * datagrams, the stored entries — and hands that to assertExposes():
 *
 * ```php
 * $kit = new MetricsConformance;
 * $kit->replay($sink);
 *
 * $kit->assertExposes($this->familiesFromScrape(), omitted: []);
 * ```
 *
 * The bridge reports families by name: a Prometheus histogram's
 * `_bucket`, `_sum`, and `_count` lines are one family. Anything under
 * the `laraflow_` prefix the document does not define fails, as does a
 * defined family that is missing and not declared omitted.
 */
final class MetricsConformance
{
    /** The tenant every fixture event carries, so the tenant label is exercised. */
    public const TENANT = 'acme';

    /** The workflow every fixture event names. */
    public const WORKFLOW = 'App\\Workflows\\Conformance';

    /** The step every fixture step event names. */
    public const STEP = 'App\\Workflows\\Steps\\Conform';

    private readonly MetricCatalog $catalog;

    public function __construct(bool $tenantLabel = false)
    {
        $this->catalog = new MetricCatalog($tenantLabel);
    }

    public function catalog(): MetricCatalog
    {
        return $this->catalog;
    }

    /**
     * One event per mapped event class, and one more wherever an event
     * decides a label value — every outcome, kind, and status class —
     * so a replay reaches every series docs/metrics.md defines.
     *
     * @return list<object>
     */
    public function events(): array
    {
        $w = self::WORKFLOW;
        $s = self::STEP;
        $t = self::TENANT;
        $at = new DateTimeImmutable('2026-11-04 12:00:00');

        return [
            new WorkflowExecutionDispatched(1, $w, 'database', 'workflows', null, $t),
            new WorkflowExecutionStarted(1, $w, $at, null, $t, queueLagSeconds: 3),
            new WorkflowExecutionCompleted(1, $w, $at, null, $t, durationMs: 1500),
            new WorkflowExecutionFailed(2, $w, 'RuntimeException', 'boom', $at, null, $t, durationMs: 800),
            new WorkflowExecutionCancelled(3, $w, $at, 'operator', null, $t, durationMs: 200),
            new WorkflowExecutionRetryScheduled(4, $w, RecoveryReason::RetryDue, 2, null, $t),
            new WorkflowExecutionDeferred(5, $w, null, $t, TenantCeiling::Concurrency, 4, 4, $at),
            new WorkflowExecutionSignalled(6, $w, null, $t, 'approved'),
            new WorkflowPlanMigrated(7, $w, 1, 2, 3, null, $t),
            new WorkflowStepCompleted(1, $w, $s, 0, 1, $at, null, $t, durationMs: 120),
            new WorkflowStepFailed(2, $w, $s, 0, 1, 'RuntimeException', 'boom', null, $t, durationMs: 90),
            new WorkflowStepSkipped(1, $w, null, $t, $s, 1),
            new WorkflowStepRetryScheduled(2, $w, $s, 0, 1, 3, 'RuntimeException', 'boom', $at, 30, null, $t),
            new WorkflowStepWaiting(6, $w, null, $t, $s, 0, 'approved', null, null),
            new WorkflowStepWaiting(6, $w, null, $t, $s, 1, null, $at, null),
            new WorkflowFanOutStarted(8, $w, null, $t, $s, 0, 3, [9, 10, 11]),
            new WorkflowCompensationStarted(2, $w, null, $t, 2, 'RuntimeException'),
            new WorkflowCompensated(2, $w, null, $t, 2, 0, true),
            new WorkflowCompensated(3, $w, null, $t, 1, 1, false),
            new WorkflowScheduleTicked(12, $w, null, null, $at, 2, false),
            new WorkflowScheduleTicked(13, $w, null, null, $at, 0, true),
            new WorkflowScheduleSkipped($w, null, null, $at, 12),
            new TriggerAccepted('signed', 6, 'approved'),
            new TriggerRefused('signed', TriggerRefusalReason::InvalidSignature, 6, 'approved'),
            new ConnectorRequestSent('crm', 'default', 'GET', '/contacts', 200, true, 150, 1, $t),
            new ConnectorRequestSent('crm', 'default', 'POST', '/contacts', 503, false, 2400, 3, $t),
            new ConnectorRequestFailed('crm', 'default', 'GET', '/contacts', 'Laraflow\\Exceptions\\TransportException', null, 10000, 2, $t),
        ];
    }

    /**
     * A snapshot with every field present, so every gauge is published.
     */
    public function snapshot(): MetricsSnapshot
    {
        return new MetricsSnapshot(
            executions: [self::WORKFLOW => ['pending' => 2, 'failed' => 1]],
            oldestUnfinishedAgeSeconds: 90,
            retriesDue: 1,
            waitsDue: 1,
            overdueSteps: 1,
            deferred: 1,
            stalledSchedules: 1,
            takenAt: new DateTimeImmutable('2026-11-04 12:00:00'),
        );
    }

    /**
     * Put every fixture event and the fixture snapshot through the core
     * mappings into $sink — what the application would send it.
     */
    public function replay(MetricsSink $sink): void
    {
        $events = new EventMetrics($sink, $this->catalog);

        foreach ($this->events() as $event) {
            $events->record($event);
        }

        (new SnapshotMetrics($sink, $this->catalog))->publish($this->snapshot());
    }

    /**
     * Assert a backend exposes exactly the families docs/metrics.md
     * defines, with their types and label names.
     *
     * @param  array<string, array{type: string, labels: list<string>}>  $exposed  What the backend shows, by family name.
     * @param  list<string>  $omitted  Families the backend cannot represent; each must be defined, and named in the bridge's README.
     */
    public function assertExposes(array $exposed, array $omitted = []): void
    {
        $problems = [];
        $defined = [];

        foreach ($omitted as $name) {
            $this->catalog->get($name);
        }

        foreach ($this->catalog->all() as $metric) {
            $defined[] = $metric->name;

            if (in_array($metric->name, $omitted, true)) {
                if (isset($exposed[$metric->name])) {
                    $problems[] = "{$metric->name} is declared omitted but is exposed";
                }

                continue;
            }

            if (! isset($exposed[$metric->name])) {
                $problems[] = "{$metric->name} is not exposed";

                continue;
            }

            if ($exposed[$metric->name]['type'] !== $metric->type->value) {
                $problems[] = "{$metric->name} is a {$exposed[$metric->name]['type']}, not a {$metric->type->value}";
            }

            $expected = $metric->labels;
            $actual = $exposed[$metric->name]['labels'];
            sort($expected);
            sort($actual);

            if ($expected !== $actual) {
                $problems[] = sprintf('%s carries [%s], not [%s]', $metric->name, implode(', ', $actual), implode(', ', $expected));
            }
        }

        foreach (array_keys($exposed) as $name) {
            if (str_starts_with($name, 'laraflow_') && ! in_array($name, $defined, true)) {
                $problems[] = "{$name} is under the laraflow_ prefix but docs/metrics.md does not define it";
            }
        }

        Assert::assertSame([], $problems, 'The bridge does not publish what docs/metrics.md defines.');
    }

    /**
     * The families a RecordingMetricsSink saw, in the shape
     * assertExposes() reads — the kit checking the core mapping itself.
     *
     * @return array<string, array{type: string, labels: list<string>}>
     */
    public function exposedBy(RecordingMetricsSink $sink): array
    {
        $exposed = [];

        foreach ($sink->families as $name => $metric) {
            $exposed[$name] = ['type' => $metric->type->value, 'labels' => $metric->labels];
        }

        return $exposed;
    }
}
