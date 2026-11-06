<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature;

use Illuminate\Support\Env;
use Laraflow\Tests\Support\ApiSurface;
use Laraflow\Tests\TestCase;
use ReflectionClass;

/**
 * The 1.0 freeze, held by a machine.
 *
 * `docs/public-api.md` says which classes are Public and
 * `compatibility.md` says a Public change waits for a major. Neither
 * document can fail a build. This can: every Public class's shape —
 * what it extends and implements, the signatures it declares, its
 * constants and their values, its properties, its enum cases — is
 * committed to `tests/api-baseline.json`, and any difference fails
 * here with the member named.
 *
 * The point is not that the surface can never move. It is that moving
 * it is a *deliberate, reviewable diff* that has to argue for itself in
 * a pull request, the way `SchemaFreezeTest` already makes a schema
 * change argue for itself ([decision 0012](../../docs/decisions/0012-what-1.0-freezes.md)).
 *
 * To record an intended change:
 *
 *     LARAFLOW_UPDATE_API_BASELINE=1 vendor/bin/phpunit --filter ApiSignatureTest
 *
 * then read the diff before committing it, and add the changelog entry
 * it implies — *Added* for a new member, *Deprecated* then *Removed*
 * across a major for one that goes.
 */
class ApiSignatureTest extends TestCase
{
    public function test_the_public_api_matches_the_committed_baseline(): void
    {
        $current = ApiSurface::build($this->path('src'));

        if (Env::get('LARAFLOW_UPDATE_API_BASELINE') !== null) {
            file_put_contents(
                $this->baselinePath(),
                json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            );

            $this->markTestSkipped('The API baseline was rewritten; read the diff before committing it.');
        }

        $this->assertFileExists(
            $this->baselinePath(),
            'The API baseline is missing. Generate it with LARAFLOW_UPDATE_API_BASELINE=1.',
        );

        /** @var array<string, mixed> $baseline */
        $baseline = json_decode((string) file_get_contents($this->baselinePath()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            array_keys($baseline),
            array_keys($current),
            'The set of Public classes changed. A class was added, removed, or had its stability level moved; '
            .'that is a release-note decision, not an edit.',
        );

        // Per class, so a failure names the one that moved rather than
        // printing the whole surface.
        foreach ($baseline as $class => $shape) {
            $this->assertSame(
                $shape,
                $current[$class] ?? null,
                "The Public API of [{$class}] changed. If that is intended, rewrite the baseline with "
                .'LARAFLOW_UPDATE_API_BASELINE=1 and say so in CHANGELOG.md.',
            );
        }
    }

    /**
     * The baseline covers what the stability document calls Public, and
     * nothing else — a guard against the baseline quietly emptying and
     * the test passing on nothing.
     */
    public function test_the_baseline_covers_every_public_class(): void
    {
        $public = array_values(array_filter(
            ApiSurface::classesIn($this->path('src')),
            fn (string $class): bool => ApiSurface::levelOf(new ReflectionClass($class)) === 'Public',
        ));

        $this->assertGreaterThan(100, count($public), 'The Public surface collapsed; the marker reading is wrong.');

        /** @var array<string, mixed> $baseline */
        $baseline = json_decode((string) file_get_contents($this->baselinePath()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($public, array_keys($baseline));
    }

    protected function baselinePath(): string
    {
        return $this->path('tests/api-baseline.json');
    }

    protected function path(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
