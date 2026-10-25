<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Laraflow\Core\LaraflowManager;
use Laraflow\Core\LaraflowServiceProvider;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\HttpConnector;
use Laraflow\Testing\ConnectorContract;
use Laraflow\Tests\Support\BlindConnector;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\Support\LeakyConnector;
use Laraflow\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The published contract harness, applied to the connectors Laraflow
 * ships — so the assertions a third party is asked to run are ones the
 * package's own connectors pass — and then proved to have teeth.
 */
class ConnectorContractTest extends TestCase
{
    public function test_the_bundled_github_connector_meets_the_contract(): void
    {
        config(['laraflow.integrations.github.connections.default.credentials.token' => 'ghp_contract_secret']);

        (new ConnectorContract)->assertHeldBy(
            $this->connector('github'),
            credential: 'ghp_contract_secret',
            probe: new ConnectorRequest('GET', '/user'),
        );
    }

    public function test_the_bundled_slack_connector_meets_the_contract(): void
    {
        config(['laraflow.integrations.slack.connections.default.credentials.token' => 'xoxb_contract_secret']);

        (new ConnectorContract)->assertHeldBy(
            $this->connector('slack'),
            credential: 'xoxb_contract_secret',
            probe: new ConnectorRequest('GET', '/auth.test'),
        );
    }

    public function test_an_application_connector_meets_the_contract(): void
    {
        config(['laraflow.integrations.crm' => [
            'connector' => FakeHttpConnector::class,
            'connections' => ['default' => ['credentials' => ['token' => 'crm_contract_secret']]],
        ]]);
        $this->app->register(LaraflowServiceProvider::class, force: true);

        (new ConnectorContract)->assertHeldBy(
            $this->connector('crm'),
            credential: 'crm_contract_secret',
            probe: new ConnectorRequest('GET', '/contacts'),
        );
    }

    public function test_the_contract_catches_a_connector_that_holds_its_credential(): void
    {
        config(['laraflow.integrations.leaky' => [
            'connector' => LeakyConnector::class,
            'connections' => ['default' => ['credentials' => ['token' => 'leaked_secret']]],
        ]]);
        $this->app->register(LaraflowServiceProvider::class, force: true);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/holds its credential/');

        (new ConnectorContract)->assertHoldsNoCredentials($this->connector('leaky'), 'leaked_secret');
    }

    public function test_the_contract_catches_a_connector_that_misclassifies_a_status(): void
    {
        config(['laraflow.integrations.blind' => [
            'connector' => BlindConnector::class,
            'connections' => ['default' => ['credentials' => ['token' => 't']]],
        ]]);
        $this->app->register(LaraflowServiceProvider::class, force: true);

        $this->expectException(AssertionFailedError::class);

        (new ConnectorContract)->assertClassifiesErrors($this->connector('blind'), new ConnectorRequest('GET', '/x'));
    }

    protected function connector(string $name): HttpConnector
    {
        $connector = $this->app->make(LaraflowManager::class)->connector($name);
        $this->assertInstanceOf(HttpConnector::class, $connector);

        return $connector;
    }
}
