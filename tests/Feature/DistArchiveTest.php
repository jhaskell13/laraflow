<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature;

use Laraflow\Tests\TestCase;

/**
 * What an application actually vendors.
 *
 * `.gitattributes` trims the dist archive with `export-ignore`, and
 * CONTRIBUTING has said since Milestone 6 to "check it with `git
 * archive HEAD | tar -t`" — by hand, which meant nobody did. The
 * Milestone 12 audit ran it and found a tracked 305 KB file named
 * `tatus`: captured `git log` output from a mistyped redirect, swept
 * into a commit by `git add -A` and shipped to every installation.
 *
 * This is that manual check, automated. It is not about one stray
 * file: it is that the set of things an application receives should be
 * a decision, and a decision that a machine notices someone changing.
 */
class DistArchiveTest extends TestCase
{
    /**
     * Everything the dist archive may contain at its top level.
     * Adding to this list is a deliberate act; see `.gitattributes`.
     *
     * @var list<string>
     */
    private const SHIPPED = [
        'CHANGELOG.md',
        'CODEOWNERS',
        'CONTRIBUTING.md',
        'LICENSE',
        'MAINTAINERS.md',
        'README.md',
        'SECURITY.md',
        'composer.json',
        'config',
        'database',
        'docs',
        'resources',
        'src',
        'stubs',
    ];

    public function test_the_dist_archive_ships_exactly_what_it_means_to(): void
    {
        $root = dirname(__DIR__, 2);

        // A worktree's `.git` is a file holding a gitdir pointer, not a
        // directory — checking for a directory made this skip silently
        // wherever the work was actually being done.
        if (! file_exists($root.'/.git')) {
            $this->markTestSkipped('Not a git checkout; the dist archive cannot be built here.');
        }

        $entries = [];
        $command = 'git -C '.escapeshellarg($root).' archive HEAD 2>/dev/null | tar -t 2>/dev/null';
        $handle = popen($command, 'r');

        if ($handle === false) {
            $this->markTestSkipped('git or tar is unavailable.');
        }

        while (($line = fgets($handle)) !== false) {
            $top = explode('/', trim($line))[0];

            if ($top !== '') {
                $entries[$top] = true;
            }
        }

        pclose($handle);

        if ($entries === []) {
            $this->markTestSkipped('The archive could not be built; nothing to check.');
        }

        $shipped = array_keys($entries);
        sort($shipped);
        $expected = self::SHIPPED;
        sort($expected);

        $this->assertSame(
            $expected,
            $shipped,
            'The dist archive changed. Anything new here is vendored into every application that installs Laraflow: '
            .'add it to .gitattributes `export-ignore` if it is development scaffolding, or to this list if it belongs.',
        );
    }
}
