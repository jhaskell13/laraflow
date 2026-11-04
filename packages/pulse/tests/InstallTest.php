<?php

declare(strict_types=1);

namespace Laraflow\Pulse\Tests;

use Illuminate\Support\Facades\Event;
use Laraflow\Events\WorkflowExecutionStarted;

/**
 * docs/metrics.md, "What every bridge must do": installed and not
 * enabled, the bridge changes nothing.
 */
final class InstallTest extends TestCase
{
    public function test_installing_the_bridge_and_doing_nothing_else_changes_nothing(): void
    {
        $this->assertFalse(config('laraflow-pulse.enabled'));
        $this->assertFalse(config('laraflow-pulse.tenant_label'));
        $this->assertFalse(Event::hasListeners(WorkflowExecutionStarted::class));
    }
}
