<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use Laraflow\Events\WorkflowExecutionRetryScheduled;
use Laraflow\Workflow\RecoveryReason;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RecoveryReasonTest extends TestCase
{
    /**
     * The deprecation contract: every REASON_* constant the event carried
     * before Milestone 8 is the backing value of exactly one case, so a
     * listener that kept comparing `$event->reason->value` to a constant
     * keeps matching until the constants are removed in 0.4.0 — and the
     * constants cannot outlive that without this test naming them.
     */
    public function test_every_deprecated_constant_is_a_case_and_every_case_has_a_constant(): void
    {
        $constants = (new ReflectionClass(WorkflowExecutionRetryScheduled::class))->getConstants();
        $constants = array_filter($constants, fn (string $name): bool => str_starts_with($name, 'REASON_'), ARRAY_FILTER_USE_KEY);
        $constants = array_values($constants);
        sort($constants);

        $values = array_column(RecoveryReason::cases(), 'value');
        sort($values);

        $this->assertSame($values, $constants, 'The REASON_* constants and the enum cases have drifted apart.');
    }

    public function test_the_labels_are_the_words_the_documentation_uses(): void
    {
        $this->assertSame(
            ['stale claim', 'step timeout', 'retry due', 'wait due', 'never dispatched'],
            array_map(fn (RecoveryReason $r) => $r->label(), RecoveryReason::cases()),
        );
    }

    /**
     * The abandonment bound applies to interruptions only; scheduled work
     * is bounded by the step's own policy. The classification lives on
     * the enum so RecoveryService and the documentation read one source.
     */
    public function test_interruptions_are_distinguished_from_scheduled_work(): void
    {
        $this->assertTrue(RecoveryReason::StaleClaim->isInterruption());
        $this->assertTrue(RecoveryReason::StepTimeout->isInterruption());
        $this->assertTrue(RecoveryReason::NeverDispatched->isInterruption());
        $this->assertFalse(RecoveryReason::RetryDue->isInterruption());
        $this->assertFalse(RecoveryReason::WaitDue->isInterruption());
    }
}
