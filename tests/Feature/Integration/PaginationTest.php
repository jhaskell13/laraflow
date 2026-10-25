<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Integration;

use Generator;
use Illuminate\Support\Facades\Http;
use Laraflow\Core\LaraflowManager;
use Laraflow\Exceptions\Connector\NotFoundException;
use Laraflow\Exceptions\Connector\PaginationException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\Github\GithubConnector;
use Laraflow\Tests\Support\FakeHttpConnector;
use Laraflow\Tests\TestCase;

/**
 * Paging end to end: lazily, bounded, and refusing rather than
 * quietly returning a short list.
 */
class PaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'laraflow.integrations.github.connections.default.credentials.token' => 'gh-token',
            'laraflow.integrations.crm' => [
                'connector' => FakeHttpConnector::class,
                'connections' => ['default' => ['credentials' => ['token' => 'crm-token']]],
            ],
        ]);

        $this->app->register(\Laraflow\Core\LaraflowServiceProvider::class, force: true);
    }

    public function test_it_follows_link_headers_to_the_last_page(): void
    {
        Http::fake([
            'api.github.com/repos?page=2' => Http::response([['id' => 3]]),
            'api.github.com/*' => Http::response([['id' => 1], ['id' => 2]], 200, [
                'Link' => '<https://api.github.com/repos?page=2>; rel="next"',
            ]),
        ]);

        // iterator_to_array() with its default key handling is how a
        // caller will reach for this; items from different pages must
        // not share keys and overwrite each other.
        $items = iterator_to_array($this->github()->items(new ConnectorRequest('GET', '/repos')));

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $items);
        Http::assertSentCount(2);
    }

    public function test_pages_are_fetched_lazily_and_stopping_early_fetches_no_more(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([['id' => 1]], 200, [
                'Link' => '<https://api.github.com/repos?page=2>; rel="next"',
            ]),
        ]);

        $pages = $this->github()->pages(new ConnectorRequest('GET', '/repos'));

        $this->assertInstanceOf(Generator::class, $pages);
        Http::assertNothingSent();

        $pages->current();
        Http::assertSentCount(1);

        // Abandoning the generator must not fetch the next page.
        unset($pages);
        Http::assertSentCount(1);
    }

    public function test_the_page_budget_refuses_rather_than_truncating(): void
    {
        $page = 1;

        Http::fake(function () use (&$page) {
            $page++;

            return Http::response([['id' => $page]], 200, [
                'Link' => "<https://api.github.com/repos?page={$page}>; rel=\"next\"",
            ]);
        });

        try {
            iterator_to_array($this->github()->items(new ConnectorRequest('GET', '/repos'), maxPages: 3));
            $this->fail('Expected PaginationException.');
        } catch (PaginationException $exception) {
            $this->assertStringContainsString('stopped after 3 pages', $exception->getMessage());
            $this->assertStringContainsString('result set is incomplete', $exception->getMessage());
            Http::assertSentCount(3);
        }
    }

    public function test_the_budget_defaults_to_the_connections_setting(): void
    {
        config(['laraflow.integrations.github.connections.default.max_pages' => 2]);
        $this->app->make(LaraflowManager::class)->forget('github');

        $page = 1;

        Http::fake(function () use (&$page) {
            $page++;

            return Http::response([['id' => $page]], 200, [
                'Link' => "<https://api.github.com/repos?page={$page}>; rel=\"next\"",
            ]);
        });

        $this->expectException(PaginationException::class);

        iterator_to_array($this->github()->items(new ConnectorRequest('GET', '/repos')));
    }

    public function test_a_failed_page_stops_the_iteration_with_its_error(): void
    {
        Http::fake([
            'api.github.com/repos?page=2' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/*' => Http::response([['id' => 1]], 200, [
                'Link' => '<https://api.github.com/repos?page=2>; rel="next"',
            ]),
        ]);

        $this->expectException(NotFoundException::class);

        iterator_to_array($this->github()->items(new ConnectorRequest('GET', '/repos')));
    }

    public function test_a_next_link_to_another_host_is_refused_before_it_is_followed(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([['id' => 1]], 200, [
                'Link' => '<https://evil.example/repos?page=2>; rel="next"',
            ]),
        ]);

        try {
            iterator_to_array($this->github()->items(new ConnectorRequest('GET', '/repos')));
            $this->fail('Expected PaginationException.');
        } catch (PaginationException $exception) {
            $this->assertStringContainsString('evil.example', $exception->getMessage());
            $this->assertStringContainsString('carries credentials', $exception->getMessage());
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evil.example'));
        }
    }

    public function test_a_provider_that_repeats_its_cursor_is_caught_rather_than_looped(): void
    {
        // The same link on every page: following it would spin until the
        // page budget, long after the problem is knowable.
        Http::fake([
            'api.github.com/*' => Http::response([['id' => 1]], 200, [
                'Link' => '<https://api.github.com/repos>; rel="next"',
            ]),
        ]);

        try {
            iterator_to_array($this->github()->items(new ConnectorRequest('GET', 'https://api.github.com/repos')));
            $this->fail('Expected PaginationException.');
        } catch (PaginationException $exception) {
            $this->assertStringContainsString('same page twice', $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_a_connector_without_a_paginator_says_so(): void
    {
        $connector = $this->app->make(LaraflowManager::class)->connector('crm');
        $this->assertInstanceOf(FakeHttpConnector::class, $connector);

        try {
            iterator_to_array($connector->pages(new ConnectorRequest('GET', '/contacts')));
            $this->fail('Expected PaginationException.');
        } catch (PaginationException $exception) {
            $this->assertStringContainsString('declares no paginator', $exception->getMessage());
        }
    }

    public function test_every_page_carries_the_connections_credentials(): void
    {
        Http::fake([
            'api.github.com/repos?page=2' => Http::response([]),
            'api.github.com/*' => Http::response([['id' => 1]], 200, [
                'Link' => '<https://api.github.com/repos?page=2>; rel="next"',
            ]),
        ]);

        iterator_to_array($this->github()->pages(new ConnectorRequest('GET', '/repos')));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer gh-token'));
    }

    protected function github(): GithubConnector
    {
        $connector = $this->app->make(LaraflowManager::class)->connector('github');
        $this->assertInstanceOf(GithubConnector::class, $connector);

        return $connector;
    }
}
