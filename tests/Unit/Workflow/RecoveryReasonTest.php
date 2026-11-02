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
     * keeps matching until the constants are removed in 0.4.0. One
     * direction only — a case added before then needs no constant, which
     * would be born deprecated.
     */
    public function test_every_deprecated_constant_is_the_value_of_exactly_one_case(): void
    {
        $constants = (new ReflectionClass(WorkflowExecutionRetryScheduled::class))->getConstants();
        $constants = array_filter($constants, fn (string $name): bool => str_starts_with($name, 'REASON_'), ARRAY_FILTER_USE_KEY);

        $this->assertCount(5, $constants, 'The five pre-Milestone 8 constants are the deprecation window.');

        foreach ($constants as $name => $value) {
            $this->assertInstanceOf(RecoveryReason::class, RecoveryReason::tryFrom($value), "{$name} no longer matches a case.");
        }
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
