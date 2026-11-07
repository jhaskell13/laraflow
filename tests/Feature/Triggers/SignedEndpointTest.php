<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Triggers;

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laraflow\Events\TriggerAccepted;
use Laraflow\Events\TriggerRefused;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\FixedClock;
use Laraflow\Tests\Support\StepTwo;
use Laraflow\Tests\TestCase;
use Laraflow\Triggers\TriggerRefusalReason;
use Laraflow\Triggers\Triggers;
use Laraflow\Triggers\TriggerSignature;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * docs/triggers.md, "The signed endpoint" — and the roadmap's exit
 * criterion: a signal from an unauthenticated HTTP client is refused;
 * from a signed one, it resumes the execution.
 */
class SignedEndpointTest extends TestCase
{
    private const KEY = 'test-key-0123456789abcdef0123456789abcdef';

    private const PAYLOAD = '{"approved_by":"ops@example.com","note":"Looks right."}';

    protected FixedClock $clock;

    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2026-11-03 14:30:00');
        $this->app->instance(ClockInterface::class, $this->clock);

        $this->logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);

        config()->set('laraflow.triggers.key', self::KEY);

        Triggers::routes();
    }

    public function test_an_unsigned_request_is_refused_and_the_execution_stays_waiting(): void
    {
        $id = $this->parked();

        $response = $this->post("/laraflow/triggers/executions/{$id}/signals/manager-approval", [], ['Content-Type' => 'application/json']);

        $response->assertUnauthorized();
        $response->assertExactJson(['status' => 'refused']);

        $execution = $this->executions()->find($id);
        $this->assertTrue($execution->isWaiting(), 'Nothing changed.');
        $this->assertNull($this->executions()->steps($execution)[1]->output, 'No payload was written.');
        $this->assertSame(0, StepTwo::$runs);
        $this->assertTrue($this->logger->hasRecordContaining('warning', 'refused a trigger request'));
    }

    public function test_a_signed_request_records_the_payload_and_resumes_the_execution(): void
    {
        $id = $this->parked();

        $response = $this->signed($id, 'manager-approval', self::PAYLOAD);

        $response->assertStatus(202);
        $response->assertJson(['status' => 'recorded', 'trigger' => 'signed', 'execution' => $id, 'signal' => 'manager-approval', 'delivery' => 'ran', 'execution_status' => 'completed']);

        $execution = $this->executions()->find($id);
        $this->assertTrue($execution->isCompleted(), 'The sync driver ran it to completion.');
        // assertEquals, not assertSame: MySQL keeps JSON object keys in
        // its own order (Milestone 8, step 13a), and the payload's keys
        // are what matter, not the order they come back in.
        $this->assertEquals(['approved_by' => 'ops@example.com', 'note' => 'Looks right.'], $execution->context['manager-approval']);
        $this->assertSame(1, StepTwo::$runs, 'The step after the wait ran.');
        $this->assertTrue($this->logger->hasRecordContaining('info', 'recorded a signal from a trigger'));
    }

    public function test_an_empty_body_is_a_signal_that_carries_nothing(): void
    {
        $id = $this->parked();

        $this->signed($id, 'manager-approval', '')->assertStatus(202);

        $this->assertTrue($this->executions()->find($id)->isCompleted());
    }

    public function test_a_stale_timestamp_is_refused_however_well_it_is_signed(): void
    {
        $id = $this->parked();

        $headers = TriggerSignature::sign($id, 'manager-approval', self::PAYLOAD);
        $this->clock->advance(301);

        $this->signed($id, 'manager-approval', self::PAYLOAD, $headers)->assertUnauthorized();

        // Within the tolerance, a clock a little ahead or behind is fine.
        $this->clock->advance(-301);
        $early = TriggerSignature::sign($id, 'manager-approval', self::PAYLOAD, timestamp: $this->clock->now()->getTimestamp() - 299);
        $this->signed($id, 'manager-approval', self::PAYLOAD, $early)->assertStatus(202);
    }

    public function test_a_wrong_key_a_tampered_body_and_another_targets_signature_are_all_refused(): void
    {
        $first = $this->parked();
        $second = $this->parked();

        $timestamp = $this->clock->now()->getTimestamp();

        $wrongKey = $this->headers($timestamp, TriggerSignature::compute('another-key-0123456789abcdef0123456789abc', $timestamp, $first, 'manager-approval', self::PAYLOAD));
        $this->signed($first, 'manager-approval', self::PAYLOAD, $wrongKey)->assertUnauthorized();

        $forFirst = TriggerSignature::sign($first, 'manager-approval', self::PAYLOAD);
        $this->signed($second, 'manager-approval', self::PAYLOAD, $forFirst)->assertUnauthorized();
        $this->signed($first, 'finance-approval', self::PAYLOAD, $forFirst)->assertUnauthorized();
        $this->signed($first, 'manager-approval', '{"approved_by":"attacker@example.com"}', $forFirst)->assertUnauthorized();

        $this->assertTrue($this->executions()->find($first)->isWaiting());
        $this->assertTrue($this->executions()->find($second)->isWaiting());
    }

    public function test_a_malformed_header_is_unsigned_not_an_error(): void
    {
        $id = $this->parked();
        $timestamp = $this->clock->now()->getTimestamp();

        $this->signed($id, 'manager-approval', self::PAYLOAD, $this->headers($timestamp, 'not-hex'))->assertUnauthorized();
        $this->signed($id, 'manager-approval', self::PAYLOAD, [TriggerSignature::TIMESTAMP_HEADER => 'yesterday', TriggerSignature::SIGNATURE_HEADER => 'v1='.str_repeat('a', 64)])->assertUnauthorized();
    }

    public function test_a_previous_key_still_verifies_during_a_rotation(): void
    {
        $old = 'old-key-0123456789abcdef0123456789abcdef';
        config()->set('laraflow.triggers.previous_keys', [$old]);

        $id = $this->parked();
        $timestamp = $this->clock->now()->getTimestamp();

        $this->signed($id, 'manager-approval', self::PAYLOAD, $this->headers($timestamp, TriggerSignature::compute($old, $timestamp, $id, 'manager-approval', self::PAYLOAD)))
            ->assertStatus(202);

        $this->assertTrue($this->logger->hasRecordContaining('debug', 'previous key'), 'A rotation can be finished on evidence.');
    }

    public function test_the_execution_is_looked_up_only_after_verification(): void
    {
        $this->post('/laraflow/triggers/executions/999/signals/manager-approval')->assertUnauthorized();

        $response = $this->signed(999, 'manager-approval', self::PAYLOAD);

        $response->assertNotFound();
        $response->assertJson(['status' => 'not_found', 'execution' => 999, 'signal' => 'manager-approval']);
        $this->assertTrue($this->logger->hasRecordContaining('warning', 'refused a trigger request'));
    }

    public function test_a_signal_the_execution_is_not_waiting_on_is_a_conflict_with_the_runtimes_words(): void
    {
        // An unfinished execution keeps a signal for another name in its
        // inbox, for a park that may ask for it: accepted, not refused.
        $id = $this->parked();
        $this->signed($id, 'wrong-signal', self::PAYLOAD)->assertStatus(202)
            ->assertJson(['status' => 'recorded', 'delivery' => 'buffered']);

        // Replayed after the resume ran it to the end: finished, nothing
        // will park again, and that is the conflict.
        $this->signed($id, 'manager-approval', self::PAYLOAD)->assertStatus(202);

        $response = $this->signed($id, 'wrong-signal', self::PAYLOAD);

        $response->assertStatus(409);
        $response->assertJson(['status' => 'not_waiting', 'execution' => $id, 'signal' => 'wrong-signal']);
        $this->assertStringContainsString('wrong-signal', (string) $response->json('message'));
        $this->signed($id, 'manager-approval', self::PAYLOAD)->assertStatus(409);
    }

    public function test_the_payload_limit_and_the_payload_shape_are_refused_by_name(): void
    {
        config()->set('laraflow.execution.max_payload_bytes', 64);
        $id = $this->parked();

        $large = json_encode(['note' => str_repeat('x', 200)], JSON_THROW_ON_ERROR);
        $response = $this->signed($id, 'manager-approval', $large);
        $response->assertStatus(413);
        $response->assertJson(['status' => 'too_large']);

        $response = $this->signed($id, 'manager-approval', '[1, 2, 3]');
        $response->assertStatus(422);
        $response->assertJson(['status' => 'invalid']);
        $this->assertStringContainsString('must be a JSON object', (string) $response->json('message'));

        $response = $this->signed($id, 'laraflow.child.9', self::PAYLOAD);
        $response->assertStatus(422);
        $this->assertStringContainsString('reserved', (string) $response->json('message'));

        $this->assertTrue($this->executions()->find($id)->isWaiting(), 'Refused means untouched.');
    }

    /**
     * M10 audit: a body over laraflow.triggers.max_body_bytes is refused
     * before verification hashes it — signed or not, the same 413, with
     * nothing in it about the limit or the signature.
     */
    public function test_a_body_over_the_limit_is_refused_before_it_is_verified(): void
    {
        Event::fake([TriggerRefused::class]);
        config()->set('laraflow.triggers.max_body_bytes', 100);
        $id = $this->parked();

        $large = json_encode(['note' => str_repeat('x', 200)], JSON_THROW_ON_ERROR);

        // Validly signed: still refused, because size comes first.
        $response = $this->signed($id, 'manager-approval', $large);
        $response->assertStatus(413);
        $response->assertExactJson(['status' => 'too_large']);

        // Unsigned: the same answer, not a 401 that would say "size was fine".
        $response = $this->signed($id, 'manager-approval', $large, []);
        $response->assertStatus(413);
        $response->assertExactJson(['status' => 'too_large']);

        // A declared length over the limit is refused on the header alone.
        $response = $this->signed($id, 'manager-approval', self::PAYLOAD, ['Content-Length' => '1000000']);
        $response->assertStatus(413);

        Event::assertDispatched(TriggerRefused::class, fn (TriggerRefused $event): bool => $event->reason === TriggerRefusalReason::BodyTooLarge);
        $this->assertTrue($this->executions()->find($id)->isWaiting(), 'Refused means untouched.');

        // At the limit or under, verification proceeds as before.
        $this->signed($id, 'manager-approval', self::PAYLOAD)->assertStatus(202);
    }

    public function test_the_default_body_limit_and_throttle_are_on(): void
    {
        $this->assertSame(65536, config('laraflow.triggers.max_body_bytes'));
        $this->assertContains('throttle:60,1', config('laraflow.triggers.middleware'));
    }

    /**
     * The audit's security finding, held closed: a provider keeps
     * response bodies, so nothing a step or a broker said leaves on the
     * 202 — what became of the run is on the record, and
     * execution_status says it.
     */
    public function test_the_response_never_carries_a_steps_exception_text(): void
    {
        StepTwo::$failTimes = 10;
        $id = $this->parked();

        $response = $this->signed($id, 'manager-approval', self::PAYLOAD);

        $response->assertStatus(202);
        $response->assertJson(['status' => 'recorded', 'delivery' => 'ran', 'execution_status' => 'failed']);
        $this->assertStringNotContainsString('step two blew up', (string) $response->getContent());
        $this->assertTrue($this->executions()->find($id)->isFailed(), 'The failure is on the record, where the redactor governs it.');
    }

    public function test_a_queued_delivery_answers_queued_and_a_worker_resumes_it(): void
    {
        config()->set('queue.default', 'database');
        $id = $this->parked();

        $response = $this->signed($id, 'manager-approval', self::PAYLOAD);

        $response->assertStatus(202);
        $response->assertJson(['status' => 'recorded', 'delivery' => 'queued']);
        $this->assertTrue($this->executions()->find($id)->isWaiting(), 'Recorded and due; the worker resumes it.');
        $this->assertSame(1, $this->app->make('db')->table('jobs')->count());
    }

    /**
     * A signal name that is not UTF-8 cannot reach Laraflow through
     * this endpoint at all: the URL is malformed and the HTTP stack
     * refuses it with a 400 before routing. Recorded because the
     * Milestone 12 security review went looking for a 500 here and
     * found this instead — the boundary is real, and worth a test so
     * it stays that way.
     */
    public function test_a_malformed_url_is_refused_by_the_stack_before_laraflow_sees_it(): void
    {
        $response = $this->call('POST', '/laraflow/triggers/executions/1/signals/'.rawurlencode('approval'.chr(0xFF)));

        $this->assertContains($response->getStatusCode(), [400, 401], 'Refused, and never a 500.');
        $this->assertFalse($this->logger->hasRecordContaining('error', 'Malformed UTF-8'));
    }

    public function test_a_refused_requests_signal_is_bounded_and_stripped_before_it_is_logged(): void
    {
        $hostile = rawurlencode("approval\x0forged".str_repeat('x', 400));

        $this->call('POST', '/laraflow/triggers/executions/1/signals/'.$hostile)->assertUnauthorized();

        $logged = null;

        foreach ($this->logger->records as $record) {
            if (str_contains($record['message'], 'refused a trigger request')) {
                $logged = $record['context']['signal'] ?? null;
            }
        }

        $this->assertIsString($logged);
        $this->assertLessThanOrEqual(255, strlen($logged), 'Bounded before anything repeats it.');
        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $logged), 'No control byte reaches a log line or a listener.');
    }

    public function test_no_log_line_carries_the_body(): void
    {
        $id = $this->parked();

        $this->call('POST', "/laraflow/triggers/executions/{$id}/signals/manager-approval", [], [], [], ['CONTENT_TYPE' => 'application/json'], self::PAYLOAD);
        $this->signed($id, 'manager-approval', self::PAYLOAD)->assertStatus(202);
        $this->signed($id, 'manager-approval', self::PAYLOAD)->assertStatus(409);

        $this->assertNotEmpty($this->logger->records);

        foreach ($this->logger->records as $record) {
            $line = $record['message'].' '.json_encode($record['context'], JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString('ops@example.com', $line);
            $this->assertStringNotContainsString('Looks right', $line);
            $this->assertStringNotContainsString(self::KEY, $line);
        }
    }

    public function test_the_events_carry_the_outcome_and_the_reason_and_never_the_payload(): void
    {
        Event::fake([TriggerAccepted::class, TriggerRefused::class]);

        $id = $this->parked();

        $this->post("/laraflow/triggers/executions/{$id}/signals/manager-approval")->assertUnauthorized();
        $this->signed($id, 'manager-approval', self::PAYLOAD)->assertStatus(202);

        Event::assertDispatched(TriggerRefused::class, fn (TriggerRefused $event): bool => $event->trigger === 'signed'
            && $event->reason === TriggerRefusalReason::Unsigned
            && $event->executionId === $id
            && $event->signal === 'manager-approval');

        Event::assertDispatched(TriggerAccepted::class, fn (TriggerAccepted $event): bool => $event->trigger === 'signed'
            && $event->executionId === $id
            && $event->signal === 'manager-approval');
    }

    protected function parked(): int
    {
        return $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow)->id;
    }

    /**
     * @param  array<string, string>|null  $headers  Signed under the current key when null.
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function signed(int $id, string $signal, string $body, ?array $headers = null): TestResponse
    {
        $headers ??= TriggerSignature::sign($id, $signal, $body);

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/laraflow/triggers/executions/{$id}/signals/{$signal}", [], [], [], $server, $body);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(int $timestamp, string $hex): array
    {
        return [
            TriggerSignature::TIMESTAMP_HEADER => (string) $timestamp,
            TriggerSignature::SIGNATURE_HEADER => 'v1='.$hex,
        ];
    }

    protected function executions(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
