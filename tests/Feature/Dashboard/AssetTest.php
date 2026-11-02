<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Dashboard;

use Laraflow\Dashboard\Dashboard;
use Laraflow\Tests\TestCase;

/**
 * docs/dashboard.md, "No build step, anywhere": one committed
 * stylesheet, served by the package, behind the same check as every
 * page.
 */
class AssetTest extends TestCase
{
    public function test_the_stylesheet_is_served_from_the_package_with_a_validator(): void
    {
        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        $response = $this->get('/laraflow/assets/dashboard.css');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $response->assertHeader('Cache-Control', 'max-age=3600, private');
        $this->assertStringContainsString('.lf-status--failed', (string) $response->getContent());

        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);

        // A browser that has it asks with the validator and is told so.
        $this->get('/laraflow/assets/dashboard.css', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_the_stylesheet_sits_behind_the_authorization_check(): void
    {
        Dashboard::routes();

        // The suite runs as `testing`, so the default rule denies.
        $this->get('/laraflow/assets/dashboard.css')->assertForbidden();
    }

    public function test_the_page_links_only_what_the_package_serves(): void
    {
        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        $response = $this->get('/laraflow/executions');

        $response->assertOk();
        $response->assertSee('/laraflow/assets/dashboard.css"', false);
        $response->assertSee('<script src="http://localhost/laraflow/assets/dashboard.js" defer></script>', false);

        // Nothing from outside the application — no CDN, no script host
        // — and nothing inline, so a content-security policy that
        // forbids inline script need not loosen for the dashboard.
        $html = (string) $response->getContent();
        $this->assertSame(1, preg_match_all('/<script\b/', $html), 'Exactly one script tag.');
        $this->assertSame(0, preg_match_all('/<script(?![^>]*\bsrc=)/', $html), 'No inline script.');
        $this->assertSame(0, preg_match_all('/\son[a-z]+="/i', $html), 'No inline event handlers.');
        $response->assertDontSee('cdn.', false);
        $response->assertDontSee('unpkg', false);
    }

    public function test_the_script_is_served_like_the_stylesheet(): void
    {
        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        $response = $this->get('/laraflow/assets/dashboard.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->assertStringContainsString('data-lf-refresh', (string) $response->getContent());
        $this->assertStringContainsString('data-lf-confirm', (string) $response->getContent());

        $this->get('/laraflow/assets/dashboard.js', ['If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);

        Dashboard::auth(fn (): bool => false);
        $this->get('/laraflow/assets/dashboard.js')->assertForbidden();
    }

    public function test_the_refresh_interval_is_the_pages_to_declare_and_configuration_turns_it_off(): void
    {
        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        // The script reads the interval from the page, never hard-codes
        // it; the toggle is there for the script to reveal.
        $this->get('/laraflow')
            ->assertOk()
            ->assertSee('<body class="lf" data-lf-refresh="10">', false)
            ->assertSee('data-lf-refresh-toggle', false);

        config()->set('laraflow.dashboard.refresh', 30);
        $this->get('/laraflow')->assertSee('data-lf-refresh="30"', false);

        // Off: no interval for the script to find, no toggle to show.
        // The script itself stays — the confirmation dialogs are its
        // other job.
        config()->set('laraflow.dashboard.refresh', 0);
        $this->get('/laraflow')
            ->assertOk()
            ->assertSee('<body class="lf">', false)
            ->assertDontSee('data-lf-refresh', false)
            ->assertSee('/laraflow/assets/dashboard.js', false);
    }
}
