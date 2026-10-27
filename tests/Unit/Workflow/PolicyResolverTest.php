<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use Laraflow\Workflow\Policy\FailurePolicy;
use Laraflow\Workflow\Policy\HasStepPolicy;
use Laraflow\Workflow\Policy\PolicyResolver;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowContext;
use PHPUnit\Framework\TestCase;

class PolicyResolverTest extends TestCase
{
    public function test_a_steps_own_policy_wins_over_the_workflow_and_the_default(): void
    {
        $resolver = new PolicyResolver(new StepPolicy(maxAttempts: 2));

        $policy = $resolver->forStep(
            $this->stepWithPolicy(new StepPolicy(maxAttempts: 7)),
            $this->workflowWithDefault(new StepPolicy(maxAttempts: 4)),
        );

        $this->assertSame(7, $policy->maxAttempts);
    }

    public function test_the_workflow_default_wins_over_the_configured_default(): void
    {
        $resolver = new PolicyResolver(new StepPolicy(maxAttempts: 2));

        $policy = $resolver->forStep(
            $this->plainStep(),
            $this->workflowWithDefault(new StepPolicy(maxAttempts: 4)),
        );

        $this->assertSame(4, $policy->maxAttempts);
    }

    public function test_the_configured_default_applies_when_nothing_is_declared(): void
    {
        $resolver = new PolicyResolver(new StepPolicy(maxAttempts: 2, timeoutSeconds: 15));

        $policy = $resolver->forStep($this->plainStep(), $this->workflowWithDefault(null));

        $this->assertSame(2, $policy->maxAttempts);
        $this->assertSame(15, $policy->timeoutSeconds);
    }

    public function test_the_shipped_default_disables_retries(): void
    {
        $policy = (new PolicyResolver)->forStep($this->plainStep(), $this->workflowWithDefault(null));

        $this->assertFalse($policy->retriesEnabled());
    }

    public function test_a_step_policy_applies_even_when_its_workflow_declares_one(): void
    {
        // Precedence must be per step, not all-or-nothing per workflow:
        // one slow step can declare a longer budget than its siblings.
        $resolver = new PolicyResolver;
        $workflow = $this->workflowWithDefault(new StepPolicy(maxAttempts: 3));

        $this->assertSame(3, $resolver->forStep($this->plainStep(), $workflow)->maxAttempts);
        $this->assertSame(
            9,
            $resolver->forStep($this->stepWithPolicy(new StepPolicy(maxAttempts: 9)), $workflow)->maxAttempts,
        );
    }

    public function test_a_workflow_stops_on_failure_unless_it_says_otherwise(): void
    {
        $this->assertSame(FailurePolicy::Stop, $this->workflowWithDefault(null)->failurePolicy());
    }

    protected function plainStep(): Step
    {
        return new class implements Step
        {
            public function handle(WorkflowContext $context): mixed
            {
                return null;
            }
        };
    }

    protected function stepWithPolicy(StepPolicy $policy): Step
    {
        return new class($policy) implements HasStepPolicy, Step
        {
            public function __construct(protected StepPolicy $policy) {}

            public function handle(WorkflowContext $context): mixed
            {
                return null;
            }

            public function stepPolicy(): StepPolicy
            {
                return $this->policy;
            }
        };
    }

    protected function workflowWithDefault(?StepPolicy $policy): Workflow
    {
        return new class($policy) extends Workflow
        {
            public function __construct(protected ?StepPolicy $policy) {}

            public function steps(): array
            {
                return [];
            }

            public function defaultStepPolicy(): ?StepPolicy
            {
                return $this->policy;
            }
        };
    }
}
