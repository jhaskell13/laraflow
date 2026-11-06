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
