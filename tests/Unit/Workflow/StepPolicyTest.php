<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\ClaimLostException;
use Laraflow\Exceptions\Workflow\InvalidStateTransitionException;
use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Policy\StepPolicy;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StepPolicyTest extends TestCase
{
    // ------------------------------------------------------------------
    // Attempt budget
    // ------------------------------------------------------------------

    public function test_the_default_policy_disables_retries_and_sets_no_deadline(): void
    {
        $policy = new StepPolicy;

        $this->assertFalse($policy->retriesEnabled());
        $this->assertFalse($policy->shouldRetry(new RuntimeException, 1));
        $this->assertNull($policy->timeoutSeconds);
    }

    public function test_it_retries_until_the_attempt_budget_is_spent(): void
    {
        $policy = new StepPolicy(maxAttempts: 3);

        $this->assertTrue($policy->shouldRetry(new RuntimeException, 1));
        $this->assertTrue($policy->shouldRetry(new RuntimeException, 2));
        $this->assertFalse($policy->shouldRetry(new RuntimeException, 3), 'The third attempt is the last.');
        $this->assertFalse($policy->shouldRetry(new RuntimeException, 4));
    }

    // ------------------------------------------------------------------
    // Schedule
    // ------------------------------------------------------------------

    public function test_delays_grow_exponentially_and_are_capped(): void
    {
        $policy = new StepPolicy(
            maxAttempts: 10,
            baseDelaySeconds: 5,
            multiplier: 2.0,
            maxDelaySeconds: 30,
            jitter: 0.0,
        );

        $this->assertSame(5, $policy->delaySeconds(1));
        $this->assertSame(10, $policy->delaySeconds(2));
        $this->assertSame(20, $policy->delaySeconds(3));
        $this->assertSame(30, $policy->delaySeconds(4), 'Capped at maxDelaySeconds.');
        $this->assertSame(30, $policy->delaySeconds(9));
    }

    public function test_a_multiplier_of_one_gives_a_fixed_delay(): void
    {
        $policy = new StepPolicy(maxAttempts: 5, baseDelaySeconds: 7, multiplier: 1.0, jitter: 0.0);

        $this->assertSame(7, $policy->delaySeconds(1));
        $this->assertSame(7, $policy->delaySeconds(4));
    }

    public function test_a_zero_base_delay_retries_immediately(): void
    {
        $policy = new StepPolicy(maxAttempts: 3, baseDelaySeconds: 0);

        $this->assertSame(0, $policy->delaySeconds(1));
        $this->assertSame(0, $policy->delaySeconds(2));
    }

    public function test_the_same_logical_retry_always_gets_the_same_delay(): void
    {
        $policy = new StepPolicy(maxAttempts: 5, baseDelaySeconds: 100, maxDelaySeconds: 1000);

        $this->assertSame(
            $policy->delaySeconds(1, 'execution-42:0'),
            $policy->delaySeconds(1, 'execution-42:0'),
            'A schedule must be reproducible in a test and explainable in production.',
        );
    }

    public function test_jitter_stays_inside_its_window(): void
    {
        $policy = new StepPolicy(
            maxAttempts: 5,
            baseDelaySeconds: 100,
            multiplier: 1.0,
            maxDelaySeconds: 1000,
            jitter: 0.2,
        );

        // Sampled across many seeds: ±20% of 100 is [80, 120].
        for ($i = 0; $i < 200; $i++) {
            $delay = $policy->delaySeconds(1, "execution-{$i}:0");

            $this->assertGreaterThanOrEqual(80, $delay);
            $this->assertLessThanOrEqual(120, $delay);
        }
    }

    public function test_jitter_spreads_different_executions_apart(): void
    {
        $policy = new StepPolicy(maxAttempts: 5, baseDelaySeconds: 100, maxDelaySeconds: 1000, jitter: 0.2);

        $delays = [];

        for ($i = 0; $i < 50; $i++) {
            $delays[] = $policy->delaySeconds(1, "execution-{$i}:0");
        }

        $this->assertGreaterThan(
            10,
            count(array_unique($delays)),
            'Executions failing together must not all retry at the same instant.',
        );
    }

    public function test_a_delay_never_exceeds_the_cap(): void
    {
        $policy = new StepPolicy(
            maxAttempts: 20,
            baseDelaySeconds: 5,
            multiplier: 2.0,
            maxDelaySeconds: 60,
            jitter: 0.5,
        );

        for ($i = 0; $i < 200; $i++) {
            $this->assertLessThanOrEqual(60, $policy->delaySeconds(10, "execution-{$i}:0"));
        }
    }

    public function test_executions_backed_off_to_the_ceiling_do_not_pile_onto_one_instant(): void
    {
        // The ceiling is where many executions converge on an identical
        // delay, so it is where jitter matters most. Clipping the window
        // against the cap would land every upward-jittered execution on
        // exactly max_delay; the window slides down to fit instead.
        $policy = new StepPolicy(
            maxAttempts: 20,
            baseDelaySeconds: 5,
            multiplier: 2.0,
            maxDelaySeconds: 300,
            jitter: 0.2,
        );

        $delays = [];

        for ($i = 0; $i < 300; $i++) {
            // Attempt 8 raw delay is 640s, well past the 300s cap.
            $delays[] = $policy->delaySeconds(8, "execution-{$i}:0");
        }

        $onTheCap = count(array_filter($delays, fn (int $d): bool => $d === 300));

        $this->assertLessThan(
            30,
            $onTheCap,
            'Executions must not bunch up on exactly max_delay.',
        );

        // The window is [cap - 2*jitter*cap, cap] = [180, 300].
        $this->assertGreaterThanOrEqual(180, min($delays));
        $this->assertLessThanOrEqual(300, max($delays));
        $this->assertGreaterThan(50, count(array_unique($delays)));
    }

    public function test_zero_jitter_is_exact(): void
    {
        $policy = new StepPolicy(maxAttempts: 5, baseDelaySeconds: 42, multiplier: 1.0, jitter: 0.0);

        $this->assertSame(42, $policy->delaySeconds(1, 'any-seed'));
        $this->assertSame(42, $policy->delaySeconds(1, 'another-seed'));
    }

    // ------------------------------------------------------------------
    // Which exceptions retry
    // ------------------------------------------------------------------

    public function test_retry_on_limits_retries_to_the_listed_types(): void
    {
        $policy = new StepPolicy(maxAttempts: 3, retryOn: [RuntimeException::class]);

        $this->assertTrue($policy->shouldRetry(new RuntimeException, 1));
        $this->assertFalse($policy->shouldRetry(new LogicException, 1));
    }

    public function test_dont_retry_on_wins_over_retry_on(): void
    {
        $policy = new StepPolicy(
            maxAttempts: 3,
            retryOn: [RuntimeException::class],
            dontRetryOn: [RuntimeException::class],
        );

        $this->assertFalse($policy->shouldRetry(new RuntimeException, 1));
    }

    public function test_matching_is_by_instanceof_so_subclasses_count(): void
    {
        $policy = new StepPolicy(maxAttempts: 3, retryOn: [RuntimeException::class]);

        $this->assertTrue($policy->shouldRetry(new class extends RuntimeException {}, 1));
    }

    public function test_an_empty_retry_on_retries_everything(): void
    {
        $policy = new StepPolicy(maxAttempts: 3);

        $this->assertTrue($policy->shouldRetry(new RuntimeException, 1));
        $this->assertTrue($policy->shouldRetry(new LogicException, 1));
    }

    public function test_deterministic_faults_are_never_retried_whatever_the_policy_says(): void
    {
        // A policy that claims to retry everything, many times over.
        $policy = new StepPolicy(maxAttempts: 100, retryOn: [], dontRetryOn: []);

        foreach ([
            UnserializableValueException::at('output', null),
            new WorkflowDefinitionMismatchException('the step plan changed'),
            InvalidWorkflowDefinitionException::noSteps('App\\SyncWorkflow'),
            InvalidStateTransitionException::between('step', ExecutionStatus::Completed, ExecutionStatus::Running),
            ClaimLostException::forId(1),
        ] as $exception) {
            $this->assertFalse(
                $policy->shouldRetry($exception, 1),
                $exception::class.' can only repeat its outcome; retrying it is noise.',
            );
        }
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function test_nonsensical_configuration_is_rejected_at_construction(): void
    {
        $cases = [
            'zero attempts' => fn () => new StepPolicy(maxAttempts: 0),
            'negative base delay' => fn () => new StepPolicy(baseDelaySeconds: -1),
            'negative max delay' => fn () => new StepPolicy(maxDelaySeconds: -1),
            'shrinking backoff' => fn () => new StepPolicy(multiplier: 0.5),
            'jitter above one' => fn () => new StepPolicy(jitter: 1.5),
            'negative jitter' => fn () => new StepPolicy(jitter: -0.1),
            'zero timeout' => fn () => new StepPolicy(timeoutSeconds: 0),
        ];

        foreach ($cases as $label => $construct) {
            try {
                $construct();
                $this->fail("Expected [{$label}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
