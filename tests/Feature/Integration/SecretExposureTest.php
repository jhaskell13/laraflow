<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\CredentialProvider;
use Laraflow\Integration\HttpConnector;
use Laraflow\Tests\TestCase;
use ReflectionClass;
use Throwable;

/**
 * The Milestone 5 exit criterion, as a sweep: nothing in the integration
 * layer's object graph, and no exception it raises, renders the token.
 *
 * Renders every object the way an exception page, a log formatter, or a
 * debugging session would, and checks every stack frame belonging to a
 * Laraflow class. Framework frames below the connector are outside its
 * control — see SECURITY.md on zend.exception_ignore_args.
 */
class SecretExposureTest extends TestCase
{
    protected const TOKEN = 'ghp_sweep_sentinel_7f3a';

    protected function setUp(): void
    {
        parent::setUp();

        config(['laraflow.integrations.github.connections.default.credentials.token' => self::TOKEN]);
    }

    public function test_the_connector_graph_renders_without_the_token(): void
    {
        $laraflow = $this->app->make(LaraflowManager::class);
        $connector = $laraflow->connector('github');
        $this->assertInstanceOf(HttpConnector::class, $connector);

        $credentials = $this->app->make(CredentialProvider::class)->credentials($connector->connection());

        // The credentials bag is the only object that can reveal the
        // token, and only through reveal().
        $this->assertSame(self::TOKEN, $credentials->require('token')->reveal());

        // The value objects, rendered every way PHP offers.
        foreach ([
            'connection' => $connector->connection(),
            'credentials' => $credentials,
            'secret' => $credentials->require('token'),
        ] as $name => $object) {
            foreach ($this->renderings($object) as $how => $rendered) {
                $this->assertStringNotContainsString(self::TOKEN, $rendered, "{$name} via {$how} leaked the token.");
            }
        }

        // The services, whose graphs reach the whole application through
        // the HTTP client's event dispatcher: walk every property of
        // every Laraflow-owned object instead, which is also the claim —
        // no Laraflow object holds the token.
        foreach (['connector' => $connector, 'manager' => $laraflow] as $name => $object) {
            $this->assertSame([], $this->propertiesHolding(self::TOKEN, $object), "{$name} holds the token.");
        }
    }

    public function test_a_transport_failure_renders_without_the_token(): void
    {
        Http::fake(['api.github.com/*' => fn () => throw new ConnectionException('Connection refused')]);

        try {
            $this->app->make(LaraflowManager::class)->connector('github')->request(new ConnectorRequest('GET', '/user'));
            $this->fail('Expected TransportException.');
        } catch (TransportException $exception) {
            $this->assertExceptionRendersWithoutToken($exception);
        }
    }

    public function test_a_provider_error_renders_without_the_token(): void
    {
        // The provider echoes the request back, as some do on error; the
        // exception may carry that body but its message must not, and
        // the query string — where a token may travel — must not appear.
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $response = $this->app->make(LaraflowManager::class)->connector('github')
            ->request(new ConnectorRequest('GET', '/user?access_token='.self::TOKEN));

        $error = $response->error();

        $this->assertNotNull($error);
        $this->assertExceptionRendersWithoutToken($error);
        $this->assertStringNotContainsString('access_token', $error->getMessage());
    }

    public function test_a_missing_credential_error_renders_without_other_credentials(): void
    {
        // A second credential is present; the error about the missing
        // one must not enumerate the others' values.
        config(['laraflow.integrations.github.connections.default.credentials' => [
            'token' => null,
            'other' => self::TOKEN,
        ]]);

        try {
            $this->app->make(LaraflowManager::class)->connector('github')->request(new ConnectorRequest('GET', '/user'));
            $this->fail('Expected a configuration exception.');
        } catch (Throwable $exception) {
            $this->assertExceptionRendersWithoutToken($exception);
        }
    }

    /**
     * Property paths, within Laraflow-namespaced objects reachable from
     * $root, whose string value contains $needle.
     *
     * @param  array<int, true>  $seen
     * @return list<string>
     */
    protected function propertiesHolding(string $needle, object $root, string $path = '', array &$seen = []): array
    {
        if (isset($seen[spl_object_id($root)]) || ! str_starts_with(get_class($root), 'Laraflow\\')) {
            return [];
        }

        $seen[spl_object_id($root)] = true;
        $found = [];

        for ($class = new ReflectionClass($root); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($root)) {
                    continue;
                }

                $value = $property->getValue($root);
                $here = ltrim($path.'.'.$property->getName(), '.');

                array_push($found, ...$this->valuesHolding($needle, $value, $here, $seen));
            }
        }

        return $found;
    }

    /**
     * @param  array<int, true>  $seen
     * @return list<string>
     */
    protected function valuesHolding(string $needle, mixed $value, string $path, array &$seen): array
    {
        if (is_string($value)) {
            return str_contains($value, $needle) ? [$path] : [];
        }

        if (is_array($value)) {
            $found = [];

            foreach ($value as $key => $item) {
                array_push($found, ...$this->valuesHolding($needle, $item, "{$path}[{$key}]", $seen));
            }

            return $found;
        }

        if (is_object($value)) {
            return $this->propertiesHolding($needle, $value, $path, $seen);
        }

        return [];
    }

    protected function assertExceptionRendersWithoutToken(Throwable $exception): void
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage(), get_class($e).' message leaked the token.');
            $this->assertStringNotContainsString(self::TOKEN, (string) $e, get_class($e).' string form leaked the token.');

            foreach ($e->getTrace() as $frame) {
                if (! str_starts_with($frame['class'] ?? '', 'Laraflow\\')) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    self::TOKEN,
                    print_r($frame['args'] ?? [], true),
                    sprintf('%s::%s() exposes the token in its arguments.', $frame['class'], $frame['function']),
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function renderings(object $object): array
    {
        ob_start();
        var_dump($object);
        $dump = (string) ob_get_clean();

        $renderings = [
            'print_r' => print_r($object, true),
            'var_export' => var_export($object, true),
            'var_dump' => $dump,
            'json_encode' => (string) json_encode($object),
            'array cast' => print_r((array) $object, true),
        ];

        try {
            $renderings['serialize'] = serialize($object);
        } catch (Throwable) {
            // Not serialisable (closures in the graph): cannot leak that way.
        }

        return $renderings;
    }
}
