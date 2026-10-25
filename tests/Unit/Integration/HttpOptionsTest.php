<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Integration;

use Illuminate\Config\Repository;
use InvalidArgumentException;
use Laraflow\Exceptions\ConnectorConfigurationException;
use Laraflow\Integration\Connection;
use Laraflow\Integration\Http\HttpOptions;
use PHPUnit\Framework\TestCase;

class HttpOptionsTest extends TestCase
{
    public function test_the_defaults(): void
    {
        $options = new HttpOptions;

        $this->assertNull($options->baseUrl);
        $this->assertSame(30.0, $options->timeoutSeconds);
        $this->assertSame(10.0, $options->connectTimeoutSeconds);
        $this->assertSame([], $options->headers);
        $this->assertNull($options->host());
    }

    public function test_from_config_reads_the_http_section_treating_blank_as_unset(): void
    {
        $options = HttpOptions::fromConfig(new Repository(['laraflow' => ['http' => ['timeout' => '7.5', 'connect_timeout' => '']]]));

        $this->assertSame(7.5, $options->timeoutSeconds);
        $this->assertSame(10.0, $options->connectTimeoutSeconds);
    }

    public function test_a_connection_overrides_what_it_sets_and_inherits_the_rest(): void
    {
        $defaults = (new HttpOptions(timeoutSeconds: 20.0))
            ->withBaseUrl('https://api.example')
            ->withHeaders(['Accept' => 'application/json', 'X-Client' => 'laraflow']);

        $options = $defaults->forConnection(new Connection('crm', 'eu', [
            'base_url' => 'https://eu.example/v2',
            'connect_timeout' => 3,
            'headers' => ['Accept' => 'application/vnd.example+json'],
        ]));

        $this->assertSame('https://eu.example/v2', $options->baseUrl);
        $this->assertSame('eu.example', $options->host());
        $this->assertSame(20.0, $options->timeoutSeconds, 'Not set by the connection: inherited.');
        $this->assertSame(3.0, $options->connectTimeoutSeconds);
        $this->assertSame(['Accept' => 'application/vnd.example+json', 'X-Client' => 'laraflow'], $options->headers);
    }

    public function test_blank_connection_values_mean_inherit(): void
    {
        $options = (new HttpOptions)->withBaseUrl('https://api.example')
            ->forConnection(new Connection('crm', 'eu', ['base_url' => '', 'timeout' => '']));

        $this->assertSame('https://api.example', $options->baseUrl);
        $this->assertSame(30.0, $options->timeoutSeconds);
    }

    public function test_with_headers_keeps_existing_headers_on_top(): void
    {
        $options = (new HttpOptions(headers: ['Accept' => 'kept']))->withHeaders(['Accept' => 'under', 'X-New' => 'yes']);

        $this->assertSame(['Accept' => 'kept', 'X-New' => 'yes'], $options->headers);
    }

    public function test_timeouts_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpOptions(timeoutSeconds: 0.0);
    }

    public function test_the_base_url_must_be_a_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpOptions(baseUrl: 'not a url');
    }

    public function test_an_invalid_connection_value_names_the_connection(): void
    {
        try {
            (new HttpOptions)->forConnection(new Connection('crm', 'eu', ['timeout' => -1]));
            $this->fail('Expected ConnectorConfigurationException.');
        } catch (ConnectorConfigurationException $exception) {
            $this->assertStringContainsString('laraflow.integrations.crm.connections.eu', $exception->getMessage());
            $this->assertStringContainsString('timeout', $exception->getMessage());
        }
    }

    public function test_connection_headers_must_be_an_array(): void
    {
        $this->expectException(ConnectorConfigurationException::class);

        (new HttpOptions)->forConnection(new Connection('crm', 'eu', ['headers' => 'Accept: json']));
    }
}
