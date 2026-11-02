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

    public function test_the_page_links_the_stylesheet_the_package_serves(): void
    {
        Dashboard::auth(fn (): bool => true);
        Dashboard::routes();

        $this->get('/laraflow/executions')
            ->assertOk()
            ->assertSee('/laraflow/assets/dashboard.css"', false)
            // Nothing from outside the application: no CDN, no script host.
            ->assertDontSee('cdn.', false)
            ->assertDontSee('unpkg', false)
            ->assertDontSee('<script src', false);
    }
}
