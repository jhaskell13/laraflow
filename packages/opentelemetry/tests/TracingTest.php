<?php

declare(strict_types=1);

namespace Laraflow\OpenTelemetry\Tests;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Observability\Tracing\Tracer;
use Laraflow\OpenTelemetry\OpenTelemetryTracer;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\Support\ParentWorkflow;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\WorkflowRunner;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;

/**
 * docs/tracing.md, held against what an exporter actually receives.
 */
final class TracingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StepTwo::$failTimes = 0;
    }

    public function test_the_bridge_is_the_tracer_the_core_calls(): void
    {
        $this->assertInstanceOf(OpenTelemetryTracer::class, $this->app->make(Tracer::class));
    }

    public function test_a_run_is_a_span_with_a_child_per_step_attempt_named_and_attributed_as_documented(): void
    {
        $execution = $this->runner()->run(new TwoStepWorkflow, [], new ExecutionOptions(correlationId: 'order-7', tenantId: 'acme'));
        $spans = $this->spans();

        $run = $spans['laraflow.run '.TwoStepWorkflow::class];
        $one = $spans['laraflow.step Laraflow\Tests\Support\StepOne'];
        $two = $spans['laraflow.step Laraflow\Tests\Support\StepTwo'];

        $this->assertSame(SpanKind::KIND_INTERNAL, $run->getKind());
        $this->assertSame([
            'laraflow.execution.id' => $execution->id,
            'laraflow.workflow' => TwoStepWorkflow::class,
            'laraflow.correlation_id' => 'order-7',
            'laraflow.tenant_id' => 'acme',
            'laraflow.plan_version' => 1,
            'laraflow.run.outcome' => 'completed',
        ], $this->attributes($run));

        foreach ([$one, $two] as $position => $step) {
            $this->assertSame($run->getSpanId(), $step->getParentSpanId());
            $this->assertSame($position, $this->attributes($step)['laraflow.step.position']);
            $this->assertSame(1, $this->attributes($step)['laraflow.step.attempt']);
            $this->assertSame('completed', $this->attributes($step)['laraflow.step.outcome']);
        }
    }

    public function test_a_failed_attempt_is_an_error_with_its_class_and_the_redacted_message(): void
    {
        StepTwo::$failTimes = 99;

        try {
            $this->runner()->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
        }

        $spans = $this->spans();
        $step = $spans['laraflow.step Laraflow\Tests\Support\StepTwo'];
        $run = $spans['laraflow.run '.TwoStepWorkflow::class];

        $this->assertSame(StatusCode::STATUS_ERROR, $step->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $this->attributes($step)['error.type']);
        $this->assertSame('failed', $this->attributes($step)['laraflow.step.outcome']);
        $this->assertSame('failed', $this->attributes($run)['laraflow.run.outcome']);
        $this->assertSame(StatusCode::STATUS_ERROR, $run->getStatus()->getCode());
    }

    public function test_messages_are_withheld_from_spans_when_withheld_from_events(): void
    {
        config()->set('laraflow.events.include_error_messages', false);
        $this->app->register(LaraflowServiceProvider::class, force: true);
        StepTwo::$failTimes = 99;

        try {
            $this->runner()->run(new TwoStepWorkflow);
        } catch (RuntimeException) {
        }

        $this->assertSame('', $this->spans()['laraflow.step Laraflow\Tests\Support\StepTwo']->getStatus()->getDescription());
    }

    public function test_a_parked_run_ends_waiting_and_its_resumption_is_another_run_of_the_same_trace(): void
    {
        $outer = $this->provider->getTracer('app')->spanBuilder('POST /orders')->startSpan();
        $scope = $outer->activate();
        $execution = $this->runner()->start(new ApprovalWorkflow);
        $scope->detach();
        $outer->end();

        // A worker, later, with nothing active: the origin is the parent.
        $this->runner()->execute($execution->id, new ApprovalWorkflow);
        $this->runner()->signal($execution->id, 'manager-approval', ['by' => 'ops']);
        $this->runner()->execute($execution->id, new ApprovalWorkflow);

        $runs = array_values(array_filter($this->exporter->getSpans(), fn ($span): bool => str_starts_with($span->getName(), 'laraflow.run ')));

        $this->assertCount(2, $runs);
        $this->assertSame('waiting', $runs[0]->getAttributes()->get('laraflow.run.outcome'));
        $this->assertSame('completed', $runs[1]->getAttributes()->get('laraflow.run.outcome'));

        foreach ($runs as $run) {
            $this->assertSame($outer->getContext()->getTraceId(), $run->getTraceId(), 'Every recent run joins the trace that started it.');
            $this->assertSame($outer->getContext()->getSpanId(), $run->getParentSpanId());
        }
    }

    public function test_a_run_long_after_creation_starts_a_new_trace_linked_to_the_origin(): void
    {
        $outer = $this->provider->getTracer('app')->spanBuilder('POST /orders')->startSpan();
        $scope = $outer->activate();
        $execution = $this->runner()->start(new TwoStepWorkflow);
        $scope->detach();
        $outer->end();

        Date::setTestNow(Date::now()->addHours(3));
        $this->app->register(LaraflowServiceProvider::class, force: true);
        $this->runner()->execute($execution->id, new TwoStepWorkflow);

        $run = $this->spans()['laraflow.run '.TwoStepWorkflow::class];

        $this->assertNotSame($outer->getContext()->getTraceId(), $run->getTraceId());
        $this->assertFalse($run->getParentContext()->isValid(), 'A new trace: the run has no parent.');
        $this->assertCount(1, $run->getLinks());
        $this->assertSame($outer->getContext()->getSpanId(), $run->getLinks()[0]->getSpanContext()->getSpanId());
    }

    public function test_an_active_span_in_the_running_process_is_the_parent_and_the_origin_a_link(): void
    {
        $origin = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $execution = $this->runner()->start(new TwoStepWorkflow, options: new ExecutionOptions(traceContext: $origin));

        $job = $this->provider->getTracer('app')->spanBuilder('queue job')->startSpan();
        $scope = $job->activate();
        $this->runner()->execute($execution->id, new TwoStepWorkflow);
        $scope->detach();
        $job->end();

        $run = $this->spans()['laraflow.run '.TwoStepWorkflow::class];

        $this->assertSame($job->getContext()->getSpanId(), $run->getParentSpanId());
        $this->assertSame('00f067aa0ba902b7', $run->getLinks()[0]->getSpanContext()->getSpanId());
    }

    public function test_a_sub_workflow_run_sits_under_the_step_that_started_it(): void
    {
        $this->runner()->run(new ParentWorkflow);
        $spans = $this->spans();

        $step = $spans['laraflow.step Laraflow\Tests\Support\SyncSubsidiaryStep'];
        $child = $spans['laraflow.run '.TwoStepWorkflow::class];

        $this->assertSame($step->getSpanId(), $child->getParentSpanId());
        $this->assertSame($step->getTraceId(), $child->getTraceId());
    }

    public function test_a_connector_call_is_a_client_span_with_the_path_and_never_the_query(): void
    {
        Http::fake(['crm.example/*' => Http::sequence()->push(['id' => 1])->push(['message' => 'gone'], 404)]);

        $connector = $this->app->make(LaraflowManager::class)->connector('crm');
        $connector->request(new ConnectorRequest('get', '/contacts/1?token=leak'));
        $connector->request(new ConnectorRequest('delete', '/contacts/2'));

        $get = $this->spans()['GET crm'];
        $delete = $this->spans()['DELETE crm'];

        $this->assertSame(SpanKind::KIND_CLIENT, $get->getKind());
        $this->assertSame([
            'laraflow.connector' => 'crm',
            'laraflow.connection' => 'default',
            'http.request.method' => 'GET',
            'url.path' => '/contacts/1',
            'http.response.status_code' => 200,
            'laraflow.connector.attempts' => 1,
        ], $this->attributes($get));
        $this->assertStringNotContainsString('leak', (string) json_encode($this->attributes($get)));
        $this->assertSame(StatusCode::STATUS_UNSET, $get->getStatus()->getCode());

        $this->assertSame(StatusCode::STATUS_ERROR, $delete->getStatus()->getCode());
        $this->assertSame('404', $this->attributes($delete)['error.type']);
    }

    public function test_no_span_carries_a_payload_or_an_undocumented_laraflow_attribute(): void
    {
        $this->runner()->run(new TwoStepWorkflow, ['secret' => 'hunter2']);

        $documented = [
            'laraflow.execution.id', 'laraflow.workflow', 'laraflow.correlation_id', 'laraflow.tenant_id',
            'laraflow.plan_version', 'laraflow.run.outcome', 'laraflow.step', 'laraflow.step.position',
            'laraflow.step.attempt', 'laraflow.step.outcome', 'laraflow.connector', 'laraflow.connection',
            'laraflow.connector.attempts',
        ];

        foreach ($this->exporter->getSpans() as $span) {
            $attributes = $span->getAttributes()->toArray();

            $this->assertStringNotContainsString('hunter2', (string) json_encode($attributes));
            $this->assertSame([], array_diff(array_filter(array_keys($attributes), fn (int|string $key): bool => str_starts_with((string) $key, 'laraflow.')), $documented));
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laraflow-opentelemetry.enabled', true);
        $app['config']->set('laraflow.integrations.crm', [
            'connector' => FakeHttpConnector::class,
            'connections' => ['default' => ['credentials' => ['token' => 'crm-token']]],
        ]);
    }

    private function runner(): WorkflowRunner
    {
        return $this->app->make(WorkflowRunner::class);
    }
}
