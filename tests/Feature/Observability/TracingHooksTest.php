<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Observability;

use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Exceptions\Workflow\InvalidExecutionOptionsException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Observability\Tracing\GuardedTracer;
use Laraflow\Observability\Tracing\NullTracer;
use Laraflow\Observability\Tracing\Tracer;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\Support\ParentWorkflow;
use Laraflow\Tests\Support\RecordingTracer;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;

/**
 * The boundaries the core reports to a tracer (docs/tracing.md, "The
 * hook"), the origin it remembers with each execution, and the guard
 * that keeps a broken tracer from ever touching a run.
 */
class TracingHooksTest extends TestCase
{
    private const ORIGIN = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function test_the_core_binds_a_tracer_that_does_nothing(): void
    {
        $this->assertInstanceOf(NullTracer::class, $this->app->make(Tracer::class));
        $this->assertSame(ExecutionStatus::Completed, $this->runner()->run(new TwoStepWorkflow)->status);
        $this->assertNull($this->app->make(ExecutionRepository::class)->find(1)->traceContext);
    }

    public function test_a_run_reports_its_boundaries_and_every_step_attempt_in_order(): void
    {
        $tracer = $this->tracing(new RecordingTracer);

        $this->runner()->run(new TwoStepWorkflow);

        $this->assertSame(['run:TwoStepWorkflow', 'step:StepOne#1', 'step:StepTwo#1', 'end'], $tracer->calls);
    }

    public function test_a_sub_workflow_run_inline_nests_inside_its_parents_step(): void
    {
        $tracer = $this->tracing(new RecordingTracer);

        $this->runner()->run(new ParentWorkflow);

        // The child's whole run sits inside the parent's step, and ends
        // before the parent's run does.
        $this->assertSame([
            'run:ParentWorkflow', 'step:StepOne#1', 'step:SyncSubsidiaryStep#1',
            'run:TwoStepWorkflow', 'step:StepOne#1', 'step:StepTwo#1', 'end',
            'end',
        ], $tracer->calls);
    }

    public function test_a_connector_call_is_reported_around_its_event_with_the_path_and_never_the_query(): void
    {
        $tracer = $this->tracing(new RecordingTracer);
        Http::fake(['crm.example/*' => Http::response(['id' => 1])]);

        $this->app->make(LaraflowManager::class)->connector('crm')->request(new ConnectorRequest('get', '/contacts/1?token=leak'));

        $this->assertSame(['call:GET crm /contacts/1', 'called'], $tracer->calls);
    }

    public function test_an_execution_remembers_the_trace_it_was_started_in(): void
    {
        $this->tracing(new RecordingTracer(context: self::ORIGIN));

        $execution = $this->runner()->start(new TwoStepWorkflow);

        $this->assertSame(self::ORIGIN, $execution->traceContext);
        $this->assertSame(self::ORIGIN, $this->app->make(ExecutionRepository::class)->find($execution->id)->traceContext);
    }

    public function test_a_given_origin_wins_over_the_active_trace(): void
    {
        $this->tracing(new RecordingTracer(context: self::ORIGIN));
        $given = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

        $execution = $this->runner()->start(new TwoStepWorkflow, options: new ExecutionOptions(traceContext: strtoupper($given)));

        $this->assertSame($given, $execution->traceContext, 'Normalised to lower case, as the format requires.');
    }

    public function test_an_origin_that_is_not_a_traceparent_is_refused_at_the_boundary(): void
    {
        foreach ([
            '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            '00-00000000000000000000000000000000-00f067aa0ba902b7-01',
            '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01',
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7',
            "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01\n",
        ] as $invalid) {
            try {
                new ExecutionOptions(traceContext: $invalid.'x');
                $this->fail("Accepted [{$invalid}x].");
            } catch (InvalidExecutionOptionsException $refused) {
                $this->assertStringContainsString('traceparent', $refused->getMessage());
            }
        }

        $this->assertNull((new ExecutionOptions(traceContext: '  '))->traceContext);
    }

    public function test_a_tracer_that_captures_garbage_stores_nothing(): void
    {
        $this->tracing(new RecordingTracer(context: 'not-a-traceparent'));

        $this->assertNull($this->runner()->start(new TwoStepWorkflow)->traceContext);
    }

    public function test_a_tracer_that_throws_never_touches_a_run_or_a_call(): void
    {
        $logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $logger);
        $this->tracing(new RecordingTracer(throws: true));
        Http::fake(['crm.example/*' => Http::response(['id' => 1])]);

        $this->assertSame(ExecutionStatus::Completed, $this->runner()->run(new TwoStepWorkflow)->status);
        $this->assertSame(200, $this->app->make(LaraflowManager::class)->connector('crm')->request(new ConnectorRequest('get', '/c'))->status());

        $this->assertTrue($logger->hasRecordContaining('error', 'tracer threw'), 'A broken tracer is logged, not silent.');
    }

    private function tracing(RecordingTracer $tracer): RecordingTracer
    {
        config(['laraflow.integrations.crm' => [
            'connector' => FakeHttpConnector::class,
            'connections' => ['default' => ['credentials' => ['token' => 'crm-token']]],
        ]]);

        $this->app->instance(Tracer::class, $tracer);
        $this->app->register(LaraflowServiceProvider::class, force: true);
        $this->assertInstanceOf(GuardedTracer::class, $this->app->make(GuardedTracer::class));

        return $tracer;
    }

    private function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
