<?php

declare(strict_types=1);

namespace Laraflow\Testing;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laraflow\Exceptions\Connector\AuthenticationFailedException;
use Laraflow\Exceptions\Connector\ClientErrorException;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\RateLimitedException;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Exceptions\Connector\ServerErrorException;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\HttpConnector;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use Throwable;

/**
 * The assertions a connector should satisfy, published so the
 * connectors Laraflow does not ship can be held to the same contract as
 * the ones it does.
 *
 * A plain object rather than a base test case or a trait: it collides
 * with nothing in the host test, works the same from PHPUnit, Pest, or
 * Testbench, and can be pointed at several connectors in one test.
 *
 * ```php
 * public function test_it_meets_the_connector_contract(): void
 * {
 *     config(['laraflow.integrations.crm.connections.default.credentials.token' => 'secret-token']);
 *
 *     (new ConnectorContract)->assertHeldBy(
 *         app(LaraflowManager::class)->connector('crm'),
 *         credential: 'secret-token',
 *         probe: new ConnectorRequest('GET', '/ping'),
 *     );
 * }
 * ```
 *
 * Every assertion runs against a faked HTTP client with stray requests
 * prevented, so a connector that reached the network fails rather than
 * quietly passing. Nothing here needs a real credential and nothing
 * here sends a real request.
 *
 * Run these in a test that does not install its own `Http::fake()`:
 * this harness installs one, and Laravel's stub list is append-only
 * with the earliest match winning.
 */
final class ConnectorContract
{
    /**
     * The statuses a connector must classify and the type each must
     * become — the shared vocabulary that lets an application catch
     * NotFoundException without knowing which provider raised it.
     *
     * @var array<int, class-string<RequestFailedException>>
     */
    public const STATUSES = [
        401 => AuthenticationFailedException::class,
        403 => AuthenticationFailedException::class,
        404 => NotFoundException::class,
        422 => ClientErrorException::class,
        429 => RateLimitedException::class,
        500 => ServerErrorException::class,
        503 => ServerErrorException::class,
    ];

    /**
     * What the single installed stub currently answers with.
     *
     * @var (Closure(): mixed)|null
     */
    private ?Closure $responder = null;

    private bool $stubbed = false;

    /**
     * @param  array<int, class-string<RequestFailedException>>|null  $statuses  Override for a
     *                                                                           provider that genuinely classifies a status differently.
     */
    public function __construct(private ?array $statuses = null) {}

    /**
     * Every assertion below, in one call.
     *
     * @param  string  $credential  The secret the connection is configured with.
     *                              No rendering of the connector and no message it
     *                              produces may contain it.
     * @param  ConnectorRequest  $probe  A request shaped like one the connector
     *                                   really makes. It is sent only through the stubbed client —
     *                                   which is the first assertion: a connector whose transport
     *                                   bypasses Laravel's client would send it for real, and that
     *                                   is reported as the failure rather than silently tolerated.
     *                                   Run the contract against a connection with `throw_on_error`
     *                                   off, which is the default.
     */
    public function assertHeldBy(HttpConnector $connector, string $credential, ConnectorRequest $probe): void
    {
        $this->assertIsNamed($connector);
        $this->assertIsInterceptable($connector, $probe);
        $this->assertHoldsNoCredentials($connector, $credential);
        $this->assertClassifiesErrors($connector, $probe);
        $this->assertReturnsErrorsRatherThanThrowing($connector, $probe);
        $this->assertHidesCredentialsInErrors($connector, $credential);
    }

    public function assertIsNamed(HttpConnector $connector): void
    {
        // Non-empty is the contract; HttpConnector invites overriding
        // name() for a class that serves a provider under another key.
        Assert::assertNotSame('', trim($connector->name()), 'A connector must have a name.');
    }

    /**
     * The harness must actually be able to intercept this connector.
     *
     * Everything below stubs Laravel's HTTP client. A connector that
     * overrides transport() with a client of its own would sail past
     * the stub and reach the real network, and every assertion that
     * follows would be testing the internet rather than the connector.
     * That is worth failing loudly for rather than passing quietly.
     */
    public function assertIsInterceptable(HttpConnector $connector, ConnectorRequest $probe): void
    {
        $this->respondWith(fn (): mixed => Http::response(['ok' => true]));

        $connector->request($probe);

        // Not assertNotEmpty(collection): PHPUnit treats a Countable
        // right, but older stub versions type it as an always-truthy
        // object and static analysis on the lowest supported set balks.
        Assert::assertTrue(
            Http::recorded()->isNotEmpty(),
            'This connector does not send through Laravel\'s HTTP client, so this harness cannot intercept it '
            .'and the assertions that follow would reach the network. Have its transport() use the '
            .'Illuminate\Http\Client\Factory the container provides, or fake your own transport and assert '
            .'these behaviours directly.',
        );
    }

    /**
     * The credential must not be reachable from the connector. It is
     * resolved per request, so an instance cached for the application's
     * lifetime should carry none of it.
     */
    public function assertHoldsNoCredentials(HttpConnector $connector, string $credential): void
    {
        $seen = [];

        Assert::assertSame(
            [],
            $this->search($credential, $connector, '', $seen),
            'The connector holds its credential; it should resolve one per request instead.',
        );

        Assert::assertStringNotContainsString(
            $credential,
            print_r($connector->options(), true).(string) json_encode($connector->connection()),
            'The connection or its options leak the credential.',
        );
    }

    /**
     * Each error status must become the documented type. A provider
     * that reports failure some other way — Slack's `ok: false` — should
     * be asserted separately; this covers what any provider can send.
     */
    public function assertClassifiesErrors(HttpConnector $connector, ConnectorRequest $probe): void
    {
        foreach ($this->statuses ?? self::STATUSES as $code => $expected) {
            $this->respondWith(fn (): mixed => Http::response(['message' => 'nope'], $code));

            $error = $connector->request($probe)->error();

            Assert::assertInstanceOf($expected, $error, "A {$code} should be a ".class_basename($expected).'.');
            Assert::assertSame($code, $error->status());
        }
    }

    /**
     * An error status is a result to inspect, not an exception. throw()
     * is how a caller opts into the other behaviour.
     */
    public function assertReturnsErrorsRatherThanThrowing(HttpConnector $connector, ConnectorRequest $probe): void
    {
        $this->respondWith(fn (): mixed => Http::response(['message' => 'nope'], 404));

        $response = $connector->request($probe);

        Assert::assertTrue($response->failed());
        Assert::assertSame(404, $response->status());

        try {
            $response->throw();
            Assert::fail('throw() must raise the mapped error.');
        } catch (RequestFailedException $exception) {
            Assert::assertSame($response->error(), $exception);
        }
    }

    /**
     * No message a connector produces may carry the credential — nor
     * the query string, which is where a credential travels when a
     * provider wants one there.
     */
    public function assertHidesCredentialsInErrors(HttpConnector $connector, string $credential): void
    {
        $probe = new ConnectorRequest('GET', '/probe?access_token='.$credential);

        $this->respondWith(fn (): mixed => Http::response(['message' => 'denied'], 401));

        $error = $connector->request($probe)->error();

        Assert::assertNotNull($error);
        $this->assertClean($error, $credential);

        $this->respondWith(function () use ($credential): never {
            throw new ConnectionException('refused while sending '.$credential);
        });

        try {
            $connector->request($probe);
            Assert::fail('A connection failure must raise a TransportException.');
        } catch (TransportException $exception) {
            $this->assertClean($exception, $credential);
        }
    }

    /**
     * Point the harness's single HTTP stub at a new answer.
     *
     * One stub, because `Http::fake()` *appends* to the factory's stub
     * list and the earliest match wins — faking again per case would
     * answer every later request with the first case's response, and
     * there is no public way to clear the list.
     *
     * @param  Closure(): mixed  $responder  Whatever Laravel's HTTP fake accepts.
     */
    private function respondWith(Closure $responder): void
    {
        $this->responder = $responder;

        if ($this->stubbed) {
            return;
        }

        Http::preventStrayRequests();
        Http::fake(function (): mixed {
            $responder = $this->responder;

            return $responder === null ? Http::response() : $responder();
        });

        $this->stubbed = true;
    }

    private function assertClean(Throwable $exception, string $credential): void
    {
        // The outer message only. The harness plants the credential in
        // the simulated driver failure precisely to prove the connector's
        // own message hides it; the chained previous is Laravel's and the
        // HTTP client's, and SECURITY.md names it as a sink the
        // application's exception handler must be configured for.
        Assert::assertStringNotContainsString(
            $credential,
            $exception->getMessage(),
            $exception::class.' exposes the credential.',
        );

        Assert::assertStringNotContainsString(
            'access_token',
            $exception->getMessage(),
            $exception::class.' exposes the query string, where a credential may travel.',
        );
    }

    /**
     * Property paths within Laraflow-namespaced objects reachable from
     * $root whose string value contains $needle.
     *
     * Deliberately stops at the Laraflow namespace: walking into
     * framework objects would wander the container, the application and
     * every service in it. The cost is that a credential a connector
     * handed to something else — a Guzzle client it configured once and
     * kept, say — is not seen here. Resolving credentials per request
     * rather than holding a configured client is what the contract asks
     * for; this assertion catches the common way of breaking it, not
     * every possible one.
     *
     * @param  array<int, true>  $seen
     * @return list<string>
     */
    private function search(string $needle, object $root, string $path, array &$seen): array
    {
        if (isset($seen[spl_object_id($root)]) || ! str_starts_with($root::class, 'Laraflow\\')) {
            return [];
        }

        $seen[spl_object_id($root)] = true;
        $found = [];

        for ($class = new ReflectionClass($root); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($root)) {
                    continue;
                }

                $here = ltrim($path.'.'.$property->getName(), '.');

                foreach ($this->values($needle, $property->getValue($root), $here, $seen) as $hit) {
                    $found[] = $hit;
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<int, true>  $seen
     * @return list<string>
     */
    private function values(string $needle, mixed $value, string $path, array &$seen): array
    {
        if (is_string($value)) {
            return str_contains($value, $needle) ? [$path] : [];
        }

        if (is_array($value)) {
            $found = [];

            foreach ($value as $key => $item) {
                foreach ($this->values($needle, $item, "{$path}[{$key}]", $seen) as $hit) {
                    $found[] = $hit;
                }
            }

            return $found;
        }

        return is_object($value) ? $this->search($needle, $value, $path, $seen) : [];
    }
}
