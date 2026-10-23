<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use DateTimeImmutable;
use Laraflow\Workflow\WorkflowContext;
use PHPUnit\Framework\TestCase;

class WorkflowContextTest extends TestCase
{
    public function test_it_can_store_and_retrieve_values(): void
    {
        $context = new WorkflowContext;

        $context->set('name', 'laraflow');

        $this->assertTrue($context->has('name'));
        $this->assertSame('laraflow', $context->get('name'));
    }

    public function test_it_returns_default_for_missing_values(): void
    {
        $context = new WorkflowContext;

        $this->assertFalse($context->has('missing'));
        $this->assertSame('default', $context->get('missing', 'default'));
    }

    public function test_a_stored_null_is_a_value_not_an_absence(): void
    {
        $context = new WorkflowContext;

        $context->set('optional', null);

        $this->assertTrue($context->has('optional'));
        $this->assertNull($context->get('optional', 'default'));
    }

    public function test_values_can_be_forgotten(): void
    {
        $context = new WorkflowContext(['temp' => 1, 'keep' => 2]);

        $context->forget('temp');

        $this->assertFalse($context->has('temp'));
        $this->assertSame(['keep' => 2], $context->all());
    }

    public function test_it_can_be_seeded_and_dumped(): void
    {
        $context = new WorkflowContext(['customer_id' => 42]);

        $context->set('email', 'test@example.com');

        $this->assertSame(
            ['customer_id' => 42, 'email' => 'test@example.com'],
            $context->all()
        );
    }

    public function test_it_exposes_execution_metadata(): void
    {
        $context = new WorkflowContext([], executionId: 17);

        $context->enterStep(position: 2, attempt: 3);

        $this->assertSame(17, $context->executionId());
        $this->assertSame(2, $context->stepPosition());
        $this->assertSame(3, $context->attempt());
    }

    public function test_idempotency_key_identifies_execution_and_step_but_not_attempt(): void
    {
        $context = new WorkflowContext([], executionId: 17);

        $context->enterStep(position: 2, attempt: 1);
        $firstAttemptKey = $context->idempotencyKey();

        $context->enterStep(position: 2, attempt: 2);
        $secondAttemptKey = $context->idempotencyKey();

        $this->assertSame('laraflow:17:2', $firstAttemptKey);
        $this->assertSame($firstAttemptKey, $secondAttemptKey);

        $context->enterStep(position: 3, attempt: 1);

        $this->assertNotSame($firstAttemptKey, $context->idempotencyKey());
    }

    public function test_it_exposes_the_attempt_deadline(): void
    {
        $context = new WorkflowContext(executionId: 17);
        $deadline = new DateTimeImmutable('2026-10-23 14:00:30');

        $this->assertNull($context->deadline(), 'No step entered, no deadline.');
        $this->assertNull($context->remainingSeconds());

        $context->enterStep(position: 0, attempt: 1, deadline: $deadline);

        $this->assertSame($deadline, $context->deadline());
        $this->assertSame(25, $context->remainingSeconds(new DateTimeImmutable('2026-10-23 14:00:05')));
        $this->assertSame(0, $context->remainingSeconds(new DateTimeImmutable('2026-10-23 14:01:00')), 'Never negative.');

        // The next step starts fresh: a deadline must not leak forward.
        $context->enterStep(position: 1, attempt: 1);

        $this->assertNull($context->deadline());
        $this->assertNull($context->remainingSeconds());
    }
}
