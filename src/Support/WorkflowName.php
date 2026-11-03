<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Laraflow\Workflow\Workflow;
use RuntimeException;

/**
 * @internal How a name typed by a person becomes a Workflow class — the
 * `laraflow:start` argument and the dashboard's start form both. One
 * definition, so the two cannot disagree about what `FulfilOrder`
 * refers to.
 */
final class WorkflowName
{
    /**
     * Forward slashes are accepted as namespace separators, as the
     * generators accept them, because a backslash costs quoting in
     * every shell; a bare name is looked for where `make:workflow`
     * writes, so the class the generator just wrote is reachable by
     * the name it was given. Checked by name only — nothing is built.
     *
     * @return class-string<Workflow>
     *
     * @throws InvalidArgumentException when nothing by that name is a workflow.
     */
    public static function resolve(string $name, Container $app): string
    {
        $name = trim(str_replace('/', '\\', $name), " \t\n\r\0\x0B\\");

        if ($name === '') {
            throw new InvalidArgumentException('The workflow must be named: a class, or a bare name under the application\'s Workflows namespace.');
        }

        // A bare name means the application's own Workflows namespace
        // first, and only then the global one. Laravel registers class
        // aliases for its facades — Broadcast, Queue, Log, Schema,
        // Event, and a dozen more — so `laraflow:start Broadcast` for
        // an App\Workflows\Broadcast would otherwise resolve to the
        // facade and be refused as "not a workflow", blaming the user's
        // class for a collision it did not cause. Found by the install
        // walk, which is the only place a real alias list exists.
        $candidates = str_contains($name, '\\')
            ? [$name]
            : [self::workflowsNamespace($app).$name, $name];

        foreach ($candidates as $candidate) {
            if (! class_exists($candidate)) {
                continue;
            }

            if (! is_subclass_of($candidate, Workflow::class)) {
                throw new InvalidArgumentException(
                    "[{$candidate}] is not a workflow: it does not extend ".Workflow::class.'.'
                );
            }

            return $candidate;
        }

        throw new InvalidArgumentException(sprintf(
            'No workflow class named [%s] exists%s. Give the fully qualified class name, or generate one with make:workflow.',
            $name,
            count($candidates) > 1 ? ' (also looked for '.$candidates[0].')' : '',
        ));
    }

    /**
     * Where `make:workflow` writes: the application namespace plus
     * `Workflows`. Outside a Laravel application skeleton the namespace
     * cannot be read from composer.json, and `App\` is the answer the
     * generators would give there too.
     */
    public static function workflowsNamespace(Container $app): string
    {
        $namespace = 'App\\';

        if ($app instanceof Application) {
            try {
                $namespace = $app->getNamespace();
            } catch (RuntimeException) {
                // Not an application skeleton; the default stands.
            }
        }

        return $namespace.'Workflows\\';
    }
}
