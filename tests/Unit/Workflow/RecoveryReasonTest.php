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
     * The deprecation window closed. The five REASON_* constants the
     * event carried before Milestone 8 were deprecated in 0.3.0 for
     * removal in 0.4.0 and removed in 0.5.0; the enum is the only way
     * to name a reason. A constant reintroduced here would be born
     * deprecated, so none may come back.
     */
    public function test_the_deprecated_constants_are_gone(): void
    {
        $constants = (new ReflectionClass(WorkflowExecutionRetryScheduled::class))->getConstants();

        $this->assertSame(
            [],
            array_keys(array_filter($constants, fn (string $name): bool => str_starts_with($name, 'REASON_'), ARRAY_FILTER_USE_KEY)),
            'The REASON_* constants were removed in 0.5.0; RecoveryReason is the only way to name a reason.',
        );
    }

    /**
     * What the removal was safe against: every string the constants
     * carried is still the backing value of exactly one case, so a
     * listener that compared `$event->reason->value` to the literal
     * keeps matching. Only the constants went.
     */
    public function test_every_string_the_constants_carried_is_still_a_case(): void
    {
        foreach (['stale_claim', 'never_dispatched', 'retry_due', 'step_timeout', 'wait_due'] as $value) {
            $this->assertInstanceOf(RecoveryReason::class, RecoveryReason::tryFrom($value), "'{$value}' no longer matches a case.");
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
