<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Triggers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Laraflow\Events\EventEmitter;
use Laraflow\Events\TriggerRefused;
use Laraflow\Tests\Support\AcceptingVerifier;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\RefusingVerifier;
use Laraflow\Tests\TestCase;
use Laraflow\Triggers\SignalIntent;
use Laraflow\Triggers\TriggerRefusalReason;
use Laraflow\Triggers\TriggerReporter;
use Laraflow\Triggers\Triggers;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * docs/triggers.md, "Named triggers for providers": the provider's
 * verification first, the application's resolution second, and then
 * the signed endpoint's own path.
 */
class NamedTriggerTest extends TestCase
{
    private const KEY = 'test-key-0123456789abcdef0123456789abcdef';

    private const EVENT = '{"type":"payment_intent.succeeded","data":{"object":{"id":"pi_1","amount_received":5000}}}';

    protected ArrayLogger $logger;

    protected int $parked;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);

        config()->set('laraflow.triggers.key', self::KEY);

        Triggers::routes();

        $this->parked = $this->app->make(WorkflowRunner::class)->run(new ApprovalWorkflow)->id;
    }

    public function test_a_verified_event_is_resolved_to_a_signal_and_delivered_through_the_same_path(): void
    {
        $seen = null;

        Triggers::register('stripe', AcceptingVerifier::class, function (Request $request) use (&$seen): SignalIntent {
            $seen = $request->json('type');

            return new SignalIntent($this->parked, 'manager-approval', ['amount' => $request->json('data.object.amount_received')]);
        });

        $response = $this->postJson('/laraflow/triggers/stripe', json_decode(self::EVENT, true, 512, JSON_THROW_ON_ERROR));

        $response->assertStatus(202);
        $response->assertJson(['status' => 'recorded', 'trigger' => 'stripe', 'execution' => $this->parked, 'signal' => 'manager-approval', 'delivery' => 'ran']);

        $this->assertSame('payment_intent.succeeded', $seen);
        $this->assertSame(1, AcceptingVerifier::$verified, 'The provider check ran first.');

        $execution = $this->executions()->find($this->parked);
        $this->assertTrue($execution->isCompleted());
        $this->assertSame(['amount' => 5000], $execution->context['manager-approval']);
    }

    public function test_the_verifier_refuses_before_the_resolver_runs(): void
    {
        $resolved = false;

        Triggers::register('stripe', RefusingVerifier::class, function () use (&$resolved): ?SignalIntent {
            $resolved = true;

            return null;
        });

        $response = $this->postJson('/laraflow/triggers/stripe', ['type' => 'anything']);

        $response->assertUnauthorized();
        $response->assertExactJson(['status' => 'refused']);
        $this->assertFalse($resolved, 'An unverified request is never resolved.');
        $this->assertTrue($this->executions()->find($this->parked)->isWaiting());
        $this->assertTrue($this->logger->hasRecordContaining('warning', 'refused a trigger request'));
    }

    public function test_an_event_that_signals_nothing_is_acknowledged_and_ignored(): void
    {
        Triggers::register('stripe', AcceptingVerifier::class, fn (): ?SignalIntent => null);

        $response = $this->postJson('/laraflow/triggers/stripe', ['type' => 'customer.updated']);

        $response->assertOk();
        $response->assertExactJson(['status' => 'ignored', 'trigger' => 'stripe']);
        $this->assertTrue($this->executions()->find($this->parked)->isWaiting());
    }

    public function test_a_throwing_resolver_is_a_server_error_logged_without_the_body(): void
    {
        Event::fake([TriggerRefused::class]);
        // The emitter was built in setUp around the real dispatcher;
        // rebuild the chain so the reporter announces through the fake.
        $this->app->forgetInstance(EventEmitter::class);
        $this->app->forgetInstance(TriggerReporter::class);

        Triggers::register('stripe', AcceptingVerifier::class, function (): never {
            throw new RuntimeException('Order lookup failed for ops@example.com');
        });

        $response = $this->postJson('/laraflow/triggers/stripe', ['type' => 'payment_intent.succeeded', 'email' => 'ops@example.com']);

        $response->assertStatus(500);
        $response->assertJson(['status' => 'error', 'trigger' => 'stripe']);

        $this->assertTrue($this->logger->hasRecordContaining('error', 'the resolver threw'));

        foreach ($this->logger->records as $record) {
            $this->assertStringNotContainsString('ops@example.com', $record['message'].json_encode($record['context'], JSON_THROW_ON_ERROR));
        }

        Event::assertDispatched(TriggerRefused::class, fn (TriggerRefused $event): bool => $event->trigger === 'stripe'
            && $event->reason === TriggerRefusalReason::ResolverFailed);
    }

    public function test_a_resolver_that_returns_something_else_is_a_resolver_failure(): void
    {
        Triggers::register('stripe', AcceptingVerifier::class, fn (): stdClass => new stdClass);

        $this->postJson('/laraflow/triggers/stripe', ['type' => 'x'])->assertStatus(500);
    }

    public function test_the_runtimes_refusals_answer_as_they_do_on_the_signed_endpoint(): void
    {
        Triggers::register('stripe', AcceptingVerifier::class, fn (Request $request): SignalIntent => new SignalIntent(
            (int) $request->json('execution'),
            (string) $request->json('signal'),
        ));

        $this->postJson('/laraflow/triggers/stripe', ['execution' => 999, 'signal' => 'manager-approval'])->assertNotFound();
        $this->postJson('/laraflow/triggers/stripe', ['execution' => $this->parked, 'signal' => 'wrong-signal'])->assertStatus(409);
        $this->postJson('/laraflow/triggers/stripe', ['execution' => $this->parked, 'signal' => 'manager-approval'])->assertStatus(202);
        $this->postJson('/laraflow/triggers/stripe', ['execution' => $this->parked, 'signal' => 'manager-approval'])->assertStatus(409);
    }

    public function test_an_unknown_trigger_is_not_found(): void
    {
        $response = $this->postJson('/laraflow/triggers/paypal', ['type' => 'x']);

        $response->assertNotFound();
        $response->assertJson(['status' => 'not_found', 'trigger' => 'paypal']);
    }

    public function test_registration_refuses_bad_names_duplicates_and_non_verifiers(): void
    {
        Triggers::register('stripe', AcceptingVerifier::class, fn (): ?SignalIntent => null);

        foreach ([
            ['stripe', AcceptingVerifier::class, 'already registered'],
            ['executions', AcceptingVerifier::class, 'reserved'],
            ['Str ipe', AcceptingVerifier::class, 'URL-safe slug'],
            ['paypal', stdClass::class, 'implementing'],
        ] as [$name, $verifier, $fragment]) {
            try {
                // Deliberately passes a class the declared type forbids,
                // to prove the registry refuses it at run time.
                // @phpstan-ignore argument.type
                Triggers::register($name, $verifier, fn (): ?SignalIntent => null);
                $this->fail("Registering [{$name}] with [{$verifier}] should have been refused.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($fragment, $exception->getMessage());
            }
        }
    }

    protected function executions(): ExecutionRepository
    {
        return $this->app->make(ExecutionRepository::class);
    }
}
