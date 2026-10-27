<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Workflow;

use Laraflow\Exceptions\Workflow\InvalidWorkflowDefinitionException;
use Laraflow\Exceptions\Workflow\WorkflowDefinitionMismatchException;
use Laraflow\Tests\Support\MutableWorkflow;
use Laraflow\Tests\Support\StepOne;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\StepResolver;
use Laraflow\Workflow\WorkflowExecution;
use stdClass;

/**
 * Definition validation and step construction, extracted from the
 * runner. The behaviour is also covered end to end through the runner;
 * these pin it directly, where the failure messages are decided.
 */
class StepResolverTest extends TestCase
{
    public function test_it_returns_the_validated_plan(): void
    {
        $this->assertSame(
            [StepOne::class, StepTwo::class],
            $this->resolver()->plan(new TwoStepWorkflow),
        );
    }

    public function test_a_workflow_with_no_steps_is_rejected(): void
    {
        MutableWorkflow::$steps = [];

        $this->expectException(InvalidWorkflowDefinitionException::class);
        $this->expectExceptionMessage('defines no steps');

        $this->resolver()->plan(new MutableWorkflow);
    }

    public function test_a_step_list_is_treated_as_untrusted(): void
    {
        // The declared return type of steps() is a contract for authors,
        // not a guarantee the runtime may rely on.
        foreach ([['NotAClass'], [42], [new stdClass], [TwoStepWorkflow::class]] as $steps) {
            // Deliberately invalid: this case exists to prove the runtime
            // rejects what the declared type merely asks of callers.
            /** @phpstan-ignore assign.propertyType */
            MutableWorkflow::$steps = $steps;

            try {
                $this->resolver()->plan(new MutableWorkflow);
                $this->fail('Expected rejection of '.json_encode(get_debug_type($steps[0])));
            } catch (InvalidWorkflowDefinitionException $exception) {
                $this->assertStringContainsString('position [0]', $exception->getMessage());
            }
        }
    }

    public function test_it_builds_a_step_from_the_container(): void
    {
        $this->assertInstanceOf(StepOne::class, $this->resolver()->make(StepOne::class));
    }

    public function test_a_missing_step_class_is_a_definition_mismatch(): void
    {
        $this->expectException(WorkflowDefinitionMismatchException::class);
        $this->expectExceptionMessage('no longer exists');

        $this->resolver()->make('App\Steps\Removed');
    }

    public function test_a_class_that_is_not_a_step_is_a_definition_mismatch(): void
    {
        $this->expectException(WorkflowDefinitionMismatchException::class);
        $this->expectExceptionMessage('does not implement');

        $this->resolver()->make(TwoStepWorkflow::class);
    }

    public function test_a_matching_definition_passes(): void
    {
        $this->resolver()->assertMatches(
            new TwoStepWorkflow,
            $this->execution(TwoStepWorkflow::class),
            [$this->step(0, StepOne::class), $this->step(1, StepTwo::class)],
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_a_different_workflow_class_is_refused(): void
    {
        $this->expectException(WorkflowDefinitionMismatchException::class);
        $this->expectExceptionMessage('was created from');

        $this->resolver()->assertMatches(
            new TwoStepWorkflow,
            $this->execution('App\Other'),
            [$this->step(0, StepOne::class), $this->step(1, StepTwo::class)],
        );
    }

    public function test_a_reordered_plan_is_refused(): void
    {
        $this->expectException(WorkflowDefinitionMismatchException::class);
        $this->expectExceptionMessage('does not match');

        // Same steps, different order: durable state must never be
        // reinterpreted by changed code.
        $this->resolver()->assertMatches(
            new TwoStepWorkflow,
            $this->execution(TwoStepWorkflow::class),
            [$this->step(0, StepTwo::class), $this->step(1, StepOne::class)],
        );
    }

    public function test_the_mismatch_message_names_both_plans(): void
    {
        try {
            $this->resolver()->assertMatches(
                new TwoStepWorkflow,
                $this->execution(TwoStepWorkflow::class),
                [$this->step(0, StepOne::class)],
            );
            $this->fail('Expected a mismatch.');
        } catch (WorkflowDefinitionMismatchException $exception) {
            $this->assertStringContainsString('Persisted: ['.StepOne::class.']', $exception->getMessage());
            $this->assertStringContainsString(StepTwo::class, $exception->getMessage());
        }
    }

    protected function execution(string $workflow): WorkflowExecution
    {
        return new WorkflowExecution(id: 1, workflow: $workflow);
    }

    protected function step(int $position, string $class): StepExecution
    {
        return new StepExecution(id: $position + 1, executionId: 1, step: $class, position: $position);
    }

    protected function resolver(): StepResolver
    {
        return $this->app->make(StepResolver::class);
    }
}
