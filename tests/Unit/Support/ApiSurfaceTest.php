<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Support;

use Laraflow\Queue\ExecuteWorkflowJob;
use Laraflow\Tests\Support\ApiSurface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The surface builder is judged on the one Public class that uses a
 * framework trait: what it records there must be this package's and
 * nothing of Laravel's, or the baseline depends on which Laravel is
 * installed — which is how it passed on one machine and failed CI.
 */
class ApiSurfaceTest extends TestCase
{
    public function test_a_class_using_framework_traits_records_only_its_own_members(): void
    {
        $surface = ApiSurface::build(dirname(__DIR__, 3).'/src');
        $job = $surface[ExecuteWorkflowJob::class];

        $this->assertSame(['__construct', 'failed', 'handle'], array_keys($job['methods']));
        $this->assertSame(['backoff', 'executionId', 'reexecuteFailedAt', 'timeout', 'tries'], array_keys($job['properties']));
    }

    /**
     * PHP 8.5 resolves `self` to the fully qualified class name, and
     * `parent` to the parent's, where 8.2 to 8.4 report the keyword —
     * so the same source produced two baselines and the newest
     * supported PHP failed against one generated on an older version.
     * The builder records the keyword on every version; this asserts
     * it, by proving no recorded type names the class it is declared
     * on or that class's parent.
     *
     * `parent` has no instance in the surface today, which is why CI
     * caught only the `self` half. The assertion covers it anyway: the
     * defect is the pattern, not the one place it happened to appear.
     */
    public function test_no_recorded_type_names_its_own_class_or_its_parent(): void
    {
        $surface = ApiSurface::build(dirname(__DIR__, 3).'/src');
        $absolute = [];

        foreach ($surface as $class => $shape) {
            foreach (array_filter([$class, $shape['extends'] ?? null]) as $relative) {
                // Whole token, not substring: `Laraflow\Integration\Connector`
                // is a prefix of `ConnectorRequest`, which is a different
                // class and a legitimate reference.
                $token = '/(?<![\\\\A-Za-z0-9_])'.preg_quote($relative, '/').'(?![A-Za-z0-9_\\\\])/';

                foreach ([...array_values($shape['methods'] ?? []), ...array_values($shape['properties'] ?? [])] as $rendered) {
                    if (preg_match($token, $rendered) === 1) {
                        $absolute[] = "{$class}: {$rendered}";
                    }
                }
            }
        }

        $this->assertSame([], $absolute, 'A type naming its own class or its parent must be rendered `self`/`parent`, or the baseline depends on the PHP version.');
    }

    public function test_a_self_returning_method_is_recorded_the_same_on_every_php_version(): void
    {
        $surface = ApiSurface::build(dirname(__DIR__, 3).'/src');

        // Wait::until() is declared `: self`; Secret::from() `: ?self`,
        // and Secret::equals() takes one. All three moved on 8.5.
        $this->assertSame('static until(DateTimeImmutable $instant): self', $surface[\Laraflow\Workflow\Wait::class]['methods']['until']);
        $this->assertSame('static from(mixed $value): ?self', $surface[\Laraflow\Support\Secret::class]['methods']['from']);
        $this->assertSame('equals(self $other): bool', $surface[\Laraflow\Support\Secret::class]['methods']['equals']);
    }

    public function test_the_surface_never_names_a_member_a_framework_trait_declares(): void
    {
        $surface = ApiSurface::build(dirname(__DIR__, 3).'/src');

        foreach ($surface as $class => $shape) {
            /** @var class-string $class */
            $reflection = new ReflectionClass($class);
            $pending = array_values($reflection->getTraits());
            $foreign = [];

            while (($trait = array_pop($pending)) !== null) {
                array_push($pending, ...array_values($trait->getTraits()));

                if (str_starts_with((string) $trait->getFileName(), dirname(__DIR__, 3).'/src/')) {
                    continue;
                }

                foreach ($trait->getProperties() as $property) {
                    $foreign[] = $property->getName();
                }

                foreach ($trait->getMethods() as $method) {
                    $foreign[] = $method->getName();
                }
            }

            $recorded = [...array_keys($shape['methods'] ?? []), ...array_keys($shape['properties'] ?? [])];
            $leaked = array_values(array_intersect($recorded, $foreign));

            $this->assertSame([], $leaked, "[{$class}] records members a framework trait declares: ".implode(', ', $leaked));
        }
    }
}
