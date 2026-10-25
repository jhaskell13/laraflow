<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laraflow\Tests\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * Holds the project's documentation and its code to each other.
 *
 * Two kinds of check. The first: every class, configuration key,
 * command, command option, publish tag, and cross-reference the
 * documentation names must actually exist. The second: the invariants
 * the documentation promises — no console command reaching past the
 * public contract, every lifecycle event carrying the correlation
 * identifiers, every never-retried exception named in the retry
 * contract, every shipped default being the documented one.
 *
 * Written as a milestone-closing audit and kept, because nothing here
 * fails when a sentence is rephrased. Each one fails only when the
 * documentation describes something that is not true, which is the
 * drift that five milestones of writing code and prose together
 * reliably produce.
 */
class ProjectContractsTest extends TestCase
{
    public function test_every_class_the_public_api_document_names_exists(): void
    {
        $doc = (string) file_get_contents($this->path('docs/public-api.md'));

        preg_match_all('/`(Laraflow\\\\[A-Za-z\\\\]+)`/', $doc, $matches);

        $missing = [];

        foreach (array_unique($matches[1]) as $class) {
            $class = str_replace('\\\\', '\\', $class);

            // Namespaces are named in prose ("All events live in
            // Laraflow\Events"); only a dangling class is a finding.
            if (in_array($class, ['Laraflow\\Events', 'Laraflow\\Demo'], true)) {
                continue;
            }

            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'public-api.md names classes that do not exist.');
    }

    public function test_every_config_key_the_documents_name_exists(): void
    {
        $docs = $this->documentation();

        preg_match_all('/laraflow\.([a-z_]+)\.([a-z_]+)/', $docs, $matches, PREG_SET_ORDER);

        $missing = [];

        foreach ($matches as [$full, $section, $key]) {
            if (config()->has($full)) {
                continue;
            }

            // `laraflow.retention.*` style wildcards and prose like
            // "laraflow.queue.timeout" for a key that exists are fine;
            // only a genuinely absent key is a finding.
            $missing[$full] = $full;
        }

        $this->assertSame([], array_values($missing), 'Documentation names configuration keys that do not exist.');
    }

    public function test_every_command_the_documents_name_is_registered(): void
    {
        $registered = array_keys(Artisan::all());
        $docs = $this->documentation().(string) file_get_contents($this->path('README.md'));

        // Not preceded by a slash: `laraflow/laraflow:dev-main` is a
        // Composer constraint, not a command.
        preg_match_all('/(?<![\/a-z])laraflow:([a-z]+)/', $docs, $matches);

        $missing = [];

        foreach (array_unique($matches[1]) as $name) {
            if (! in_array("laraflow:{$name}", $registered, true)) {
                $missing[] = "laraflow:{$name}";
            }
        }

        $this->assertSame([], $missing, 'Documentation names commands that are not registered.');
    }

    public function test_every_command_option_the_public_api_document_lists_is_declared(): void
    {
        $doc = (string) file_get_contents($this->path('docs/public-api.md'));
        $problems = [];

        // Each console row looks like: | `laraflow:x {--a=} {--b}` | ...
        preg_match_all('/`laraflow:([a-z]+)((?: \{[^}]+\})+)`/', $doc, $rows, PREG_SET_ORDER);

        $this->assertNotEmpty($rows, 'The console table was not found; this audit needs updating.');

        foreach ($rows as [, $name, $options]) {
            $command = Artisan::all()["laraflow:{$name}"] ?? null;

            if ($command === null) {
                $problems[] = "laraflow:{$name} is documented but not registered";

                continue;
            }

            preg_match_all('/\{--([a-z-]+)=?\*?\}/', $options, $declared);

            foreach ($declared[1] as $option) {
                if (! $command->getDefinition()->hasOption($option)) {
                    $problems[] = "laraflow:{$name} has no --{$option}";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_every_documented_exception_and_event_exists(): void
    {
        $doc = (string) file_get_contents($this->path('docs/public-api.md'));
        $missing = [];

        foreach (['Exceptions\Workflow', 'Exceptions\Connector', 'Exceptions', 'Events'] as $namespace) {
            preg_match_all('/`Laraflow\\\\\\\\'.str_replace('\\', '\\\\\\\\', $namespace).'\\\\\\\\([A-Za-z]+)`/', $doc, $matches);

            foreach (array_unique($matches[1]) as $class) {
                $fqcn = "Laraflow\\{$namespace}\\{$class}";

                if (! class_exists($fqcn)) {
                    $missing[] = $fqcn;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_every_publish_tag_the_readme_names_works(): void
    {
        foreach (['laraflow-config', 'laraflow-migrations'] as $tag) {
            $this->assertNotEmpty(
                \Illuminate\Support\ServiceProvider::$publishGroups[$tag] ?? [],
                "The {$tag} publish tag is documented but publishes nothing.",
            );
        }
    }

    public function test_every_internal_document_link_resolves(): void
    {
        $broken = [];

        foreach ($this->documents() as $file) {
            $contents = (string) file_get_contents($file);
            $base = dirname($file);

            preg_match_all('/\]\(([A-Za-z0-9_\/.-]+\.md)(#[a-z0-9-]+)?\)/', $contents, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $target = $match[1];

                $resolved = file_exists($base.'/'.$target)
                    ? $base.'/'.$target
                    : $this->path($target);

                if (! file_exists($resolved)) {
                    $broken[] = basename($file).' → '.$target;
                }
            }
        }

        $this->assertSame([], $broken);
    }

    public function test_every_public_class_is_documented(): void
    {
        $doc = (string) file_get_contents($this->path('docs/public-api.md'));
        $undocumented = [];

        foreach (File::allFiles($this->path('src')) as $file) {
            $class = 'Laraflow\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                mb_substr($file->getRealPath(), mb_strlen($this->path('src')) + 1),
            );

            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $docblock = (string) $reflection->getDocComment();

            // Demo classes are documented as a group, and traits are
            // implementation detail of the classes that use them.
            if (str_contains($class, '\\Demo\\') || $reflection->isTrait()) {
                continue;
            }

            if (str_contains($doc, class_basename($class))) {
                continue;
            }

            // An undocumented class must declare a stability level.
            // Console commands are documented by command name rather
            // than class name, and carry @experimental.
            if (! str_contains($docblock, '@internal') && ! str_contains($docblock, '@experimental')) {
                $undocumented[] = $class;
            }
        }

        $this->assertSame(
            [],
            $undocumented,
            'These classes are neither documented in public-api.md nor marked @internal.',
        );
    }

    public function test_the_shipped_defaults_are_the_documented_defaults(): void
    {
        // Every retry authority in Laraflow ships off. This is the
        // single most repeated claim in the documentation.
        $this->assertSame(1, (int) config('laraflow.retry.max_attempts'), 'Retries must ship disabled.');
        $this->assertSame(1, (int) config('laraflow.queue.tries'), 'The queue must have no retry authority.');
        $this->assertNull(config('laraflow.recovery.pending_after'), 'The pending scan must be opt-in.');
        $this->assertNull(config('laraflow.timeout.step'), 'No step deadline by default.');
        $this->assertNull(config('laraflow.errors.redactor'), 'No redactor by default.');
        $this->assertNull(config('laraflow.execution.max_payload_bytes'), 'No payload limit by default.');

        foreach (['scrub_after_days', 'prune_attempts_after_days', 'delete_after_days', 'delete_failed_after_days'] as $bound) {
            $this->assertNull(config("laraflow.retention.{$bound}"), "Retention bound {$bound} must ship disabled.");
        }

        // And the ordering the timeout contract depends on.
        $this->assertLessThan(
            (int) config('laraflow.execution.stale_after'),
            (int) config('laraflow.queue.timeout'),
            'queue.timeout must stay below execution.stale_after.',
        );
    }

    public function test_the_repository_contract_is_fully_implemented_everywhere(): void
    {
        $contract = new ReflectionClass(\Laraflow\Workflow\ExecutionRepository::class);

        $implementations = [
            \Laraflow\Workflow\Repositories\EloquentExecutionRepository::class,
            \Laraflow\Tests\Support\CrashingExecutionRepository::class,
        ];

        foreach ($implementations as $implementation) {
            $reflection = new ReflectionClass($implementation);

            foreach ($contract->getMethods() as $method) {
                $this->assertTrue(
                    $reflection->hasMethod($method->getName()),
                    "{$implementation} is missing {$method->getName()}().",
                );
            }
        }
    }

    public function test_no_console_command_reaches_past_the_public_contract(): void
    {
        $offenders = [];

        foreach (File::allFiles($this->path('src/Console')) as $file) {
            $source = (string) file_get_contents($file->getRealPath());

            // The Milestone 4 promise: anything the console can show,
            // an application can show, because they read the same API.
            if (str_contains($source, 'Workflow\\Models\\')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'A console command queries the internal Eloquent models.');
    }

    /**
     * The Milestone 5 exit criterion, as a property of the code: a
     * connector can be written against the public contracts alone.
     *
     * The bundled Slack connector is the sample. If it needed anything
     * marked @internal, a third-party package would need the same thing
     * — and the extension points would not be finished. The GitHub
     * connector is held to it too.
     */
    public function test_a_connector_can_be_built_without_reaching_into_internals(): void
    {
        $offenders = [];

        foreach ([$this->path('src/Integration/Slack'), $this->path('src/Integration/Github')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                $source = (string) file_get_contents($file->getRealPath());

                preg_match_all('/^use (Laraflow\\\\[A-Za-z\\\\]+);/m', $source, $matches);

                foreach ($matches[1] as $used) {
                    if (! class_exists($used) && ! interface_exists($used) && ! enum_exists($used)) {
                        continue;
                    }

                    $docblock = (string) (new ReflectionClass($used))->getDocComment();

                    if (str_contains($docblock, '@internal')) {
                        $offenders[] = $file->getFilename().' uses internal '.$used;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'A bundled connector depends on something an application cannot.');
    }

    public function test_every_lifecycle_event_carries_the_correlation_identifiers(): void
    {
        $missing = [];

        foreach (File::files($this->path('src/Events')) as $file) {
            $class = 'Laraflow\\Events\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class) || ! str_starts_with($file->getFilename(), 'Workflow')) {
                continue;
            }

            $constructor = (new ReflectionClass($class))->getConstructor();
            $parameters = array_map(
                fn (ReflectionParameter $p): string => $p->getName(),
                $constructor?->getParameters() ?? [],
            );

            foreach (['correlationId', 'tenantId'] as $required) {
                if (! in_array($required, $parameters, true)) {
                    $missing[] = "{$class} has no {$required}";
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_every_never_retried_exception_is_documented_as_such(): void
    {
        $doc = (string) file_get_contents($this->path('docs/retry-and-failure-policy.md'));

        foreach (\Laraflow\Workflow\Policy\StepPolicy::NEVER_RETRY as $class) {
            $this->assertStringContainsString(
                class_basename($class),
                $doc,
                class_basename($class).' is never retried but the contract does not say so.',
            );
        }
    }

    /**
     * @return list<string>
     */
    protected function documents(): array
    {
        return [
            $this->path('README.md'),
            ...array_map(
                fn ($file): string => $file->getRealPath(),
                File::files($this->path('docs')),
            ),
        ];
    }

    protected function documentation(): string
    {
        return implode("\n", array_map(
            fn (string $file): string => (string) file_get_contents($file),
            $this->documents(),
        ));
    }

    protected function path(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
