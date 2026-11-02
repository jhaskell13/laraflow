<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laraflow\Tests\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use SensitiveParameter;
use Stringable;
use Throwable;

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
        // The make:* generators are documented in the same table and
        // are just as much the package's console surface.
        preg_match_all('/`((?:laraflow|make):[a-z]+)((?: \{[^}]+\})+)`/', $doc, $rows, PREG_SET_ORDER);

        $this->assertNotEmpty($rows, 'The console table was not found; this audit needs updating.');

        foreach ($rows as [, $name, $options]) {
            $command = Artisan::all()[$name] ?? null;

            if ($command === null) {
                $problems[] = "{$name} is documented but not registered";

                continue;
            }

            preg_match_all('/\{--([a-z-]+)=?\*?\}/', $options, $declared);

            foreach ($declared[1] as $option) {
                if (! $command->getDefinition()->hasOption($option)) {
                    $problems[] = "{$name} has no --{$option}";
                }
            }

            // ...and the arguments, which the table spells the same way.
            preg_match_all('/\{([a-z]+)\??\}/', $options, $arguments);

            foreach ($arguments[1] as $argument) {
                if (! $command->getDefinition()->hasArgument($argument)) {
                    $problems[] = "{$name} has no {$argument} argument";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /**
     * The other direction, which the audit above cannot see: an option
     * added to a command and not written down ships undocumented and
     * CI stays green. Milestone 8 added a command with four options and
     * nothing structural would have caught their omission (Phase C
     * audit). Laravel's own global options are not ours to document.
     */
    public function test_every_declared_command_option_and_argument_is_documented(): void
    {
        $doc = (string) file_get_contents($this->path('docs/public-api.md'));
        $problems = [];

        $global = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'];

        $documented = [];
        preg_match_all('/`((?:laraflow|make):[a-z]+)((?: \{[^}]+\})*)`/', $doc, $rows, PREG_SET_ORDER);

        foreach ($rows as [, $name, $options]) {
            $documented[$name] = ($documented[$name] ?? '').$options;
        }

        foreach (Artisan::all() as $name => $command) {
            if (! str_starts_with($name, 'laraflow:') && ! in_array($name, ['make:workflow', 'make:step', 'make:connector'], true)) {
                continue;
            }

            if (! isset($documented[$name])) {
                $problems[] = "{$name} is registered but has no row in docs/public-api.md";

                continue;
            }

            foreach ($command->getDefinition()->getOptions() as $option) {
                if (in_array($option->getName(), $global, true)) {
                    continue;
                }

                if (! str_contains($documented[$name], '{--'.$option->getName())) {
                    $problems[] = "{$name} declares --{$option->getName()}, which is not documented";
                }
            }

            foreach ($command->getDefinition()->getArguments() as $argument) {
                if (! str_contains($documented[$name], '{'.$argument->getName())) {
                    $problems[] = "{$name} takes {$argument->getName()}, which is not documented";
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
        foreach (['laraflow-config', 'laraflow-migrations', 'laraflow-stubs'] as $tag) {
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

        foreach ($this->packageClasses() as $class) {
            $reflection = new ReflectionClass($class);
            $docblock = (string) $reflection->getDocComment();

            // Demo classes are documented as a group, and traits are
            // implementation detail of the classes that use them.
            if (str_contains($class, '\\Demo\\') || $reflection->isTrait()) {
                continue;
            }

            // The fully qualified name in backticks is the documented
            // form; events are listed by basename under their namespace
            // heading. A bare basename match would let any class named
            // like a word in the prose — Wait, Step, Clock — pass.
            $documented = str_contains($doc, '`'.$class.'`')
                || (str_starts_with($class, 'Laraflow\\Events\\') && str_contains($doc, '`'.class_basename($class).'`'));

            if ($documented) {
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

    public function test_every_class_the_api_document_names_exists(): void
    {
        $missing = [];

        foreach ($this->apiDocumentClasses() as $class => $level) {
            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'public-api.md names classes that no longer exist; renames must update the document.');
    }

    public function test_the_api_documents_stability_levels_match_the_code_markers(): void
    {
        $conflicts = [];

        foreach ($this->apiDocumentClasses() as $class => $level) {
            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                continue;
            }

            $docblock = (string) (new ReflectionClass($class))->getDocComment();

            $marked = match (true) {
                str_contains($docblock, '@internal') => 'Internal',
                str_contains($docblock, '@experimental') => 'Experimental',
                default => 'Public',
            };

            if ($marked !== $level) {
                $conflicts[] = "{$class} is {$level} in public-api.md but {$marked} by its docblock";
            }
        }

        $this->assertSame(
            [],
            $conflicts,
            'A class must carry the stability level the document promises: '
            .'the docblock is what an IDE shows, the document is what a reader trusts.',
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

    public function test_every_connector_authority_ships_off(): void
    {
        // Milestone 5's additions to the most repeated claim in the
        // documentation: nothing retries, throttles, or spends a
        // provider's budget until an application opts in.
        $this->assertSame(1, (int) config('laraflow.http.retries.max_attempts'), 'Transport retries must ship disabled.');
        $this->assertNull(config('laraflow.http.rate_limit'), 'Client-side rate limiting must ship disabled.');
        $this->assertSame(100, (int) config('laraflow.http.pagination.max_pages'), 'The page budget is the documented one.');
        $this->assertSame(3600, (int) config('laraflow.retry.max_suggested_delay'), 'The suggested-delay cap is the documented one.');
        // assertLessThanOrEqual($expected, $actual) asserts actual <= expected:
        // the cap is the actual, five seconds the bound.
        $this->assertLessThanOrEqual(
            5,
            (int) config('laraflow.http.retries.max_retry_after'),
            'A Retry-After beyond a few seconds must be left to the durable path.',
        );

        foreach (['github', 'slack'] as $connector) {
            $this->assertNull(
                config("laraflow.integrations.{$connector}.connections.default.credentials.token"),
                "The bundled {$connector} connector must ship without a credential.",
            );
        }
    }

    public function test_every_connector_exception_is_documented(): void
    {
        $doc = (string) file_get_contents($this->path('docs/public-api.md'));
        $undocumented = [];

        foreach (File::files($this->path('src/Exceptions/Connector')) as $file) {
            $class = 'Laraflow\\Exceptions\\Connector\\'.$file->getFilenameWithoutExtension();

            if (! str_contains($doc, '`'.$class.'`')) {
                $undocumented[] = $class;
            }
        }

        $this->assertSame([], $undocumented, 'A connector exception an application can catch is not in public-api.md.');
    }

    public function test_every_transport_marks_the_authenticated_request_sensitive(): void
    {
        // By the time a transport sees the request its options carry the
        // credential; the attribute is what keeps it out of Laraflow's
        // own stack frames.
        $missing = [];

        foreach ($this->packageClasses() as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, \Laraflow\Integration\Http\HttpTransport::class)) {
                continue;
            }

            $parameter = (new ReflectionClass($class))->getMethod('send')->getParameters()[0];

            if ($parameter->getAttributes(SensitiveParameter::class) === []) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'An HttpTransport::send() does not mark its request #[SensitiveParameter].');
    }

    public function test_connector_events_carry_no_request_or_response_content(): void
    {
        $forbidden = ['headers', 'options', 'body', 'raw', 'query', 'json', 'uri', 'url', 'response', 'request'];
        $offenders = [];

        foreach (File::files($this->path('src/Events')) as $file) {
            if (! str_starts_with($file->getFilename(), 'Connector')) {
                continue;
            }

            $class = 'Laraflow\\Events\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                if (in_array(strtolower($parameter->getName()), $forbidden, true)) {
                    $offenders[] = "{$class} carries {$parameter->getName()}";
                }
            }
        }

        $this->assertSame([], $offenders, 'A connector event carries request or response content.');
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

    /**
     * Milestone 8 step 1: execution and step ids are integers, on every
     * signature. The persistence has issued integers since Milestone 0
     * and the `int|string` union on the contract was a hedge nobody
     * exercised; the Milestone 7 audit asked for a commitment and this
     * is it. Reflection over every parameter and property in the
     * package named like an id, so a new event or exception cannot
     * quietly reopen the question.
     */
    public function test_execution_ids_are_integers_on_every_signature(): void
    {
        // Anything named like an id, less the two application-supplied
        // identifiers, which are strings by contract. Lists of ids are
        // array-typed and left to static analysis.
        $idNames = '/^(id|.+Id)$/';
        $stringIdentifiers = ['correlationId', 'tenantId'];
        $offenders = [];
        $inspected = 0;

        foreach ($this->packageClasses() as $class) {
            $reflection = new ReflectionClass($class);

            // Eloquent models expose ids as untyped attributes and are
            // internal to the repository, which casts them.
            if ($reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    if (preg_match($idNames, $parameter->getName()) !== 1 || in_array($parameter->getName(), $stringIdentifiers, true)) {
                        continue;
                    }

                    $inspected++;

                    if (! in_array((string) $parameter->getType(), ['int', '?int'], true)) {
                        $offenders[] = sprintf('%s::%s($%s): %s', $class, $method->getName(), $parameter->getName(), (string) $parameter->getType());
                    }
                }
            }

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class
                    || preg_match($idNames, $property->getName()) !== 1
                    || in_array($property->getName(), $stringIdentifiers, true)) {
                    continue;
                }

                $inspected++;

                if (! in_array((string) $property->getType(), ['int', '?int'], true)) {
                    $offenders[] = sprintf('%s::$%s: %s', $class, $property->getName(), (string) $property->getType());
                }
            }
        }

        $this->assertSame([], $offenders, 'An execution or step id is not typed int.');
        $this->assertGreaterThan(40, $inspected, 'The id rule inspected too few signatures to mean anything.');
    }

    /**
     * Milestone 8 step 2: the four recovery scans have one shape — a
     * cutoff, a limit, a workflow scope, a tenant scope — so whatever
     * narrows one narrows all. Compared structurally, by position, type,
     * and default, so a fifth scan or a widened one is caught here.
     */
    public function test_the_recovery_scans_share_one_signature_shape(): void
    {
        $contract = new ReflectionClass(\Laraflow\Workflow\ExecutionRepository::class);
        $shapes = [];

        foreach (['stale', 'pendingBefore', 'dueForRetry', 'waitingDue'] as $scan) {
            $shapes[$scan] = array_map(
                fn (ReflectionParameter $p): string => sprintf(
                    '%d:%s%s',
                    $p->getPosition(),
                    (string) $p->getType(),
                    $p->isDefaultValueAvailable() ? '='.var_export($p->getDefaultValue(), true) : '',
                ),
                $contract->getMethod($scan)->getParameters(),
            );
        }

        $this->assertSame(
            ['0:DateTimeImmutable', '1:int=100', '2:?string=NULL', '3:?string=NULL'],
            $shapes['stale'],
        );
        $this->assertCount(1, array_unique(array_map('serialize', $shapes)), 'The recovery scans differ in shape: '.json_encode($shapes));
    }

    /**
     * Milestone 8 step 4: a service takes every collaborator it needs,
     * and the service provider is the one place that supplies them. A
     * constructor parameter typed as a class with a null default was a
     * second, silent configuration — a runner with its own clock, an
     * observer that emitted nothing — that disagreed with the rest of
     * the application.
     *
     * A service is any package class whose constructor takes a
     * collaborator; a collaborator is an interface other than the
     * language's own, a framework class, or a package class that itself
     * takes collaborators. Derived rather than listed: the list this
     * test began as missed WaitResolver one commit after it was written.
     * The only nullable collaborators allowed are the two whose null is
     * a documented setting.
     */
    public function test_services_require_their_collaborators(): void
    {
        $nullMeansOff = [
            \Laraflow\Support\LoggingContext::class => ['context'],
            \Laraflow\Events\EventEmitter::class => ['events'],
            // The context is a value object the runner builds; one built
            // by hand has no execution behind it, and remember() on it
            // raises rather than running the work (docs/idempotency.md).
            \Laraflow\Workflow\WorkflowContext::class => ['idempotency'],
        ];

        $services = [];
        $optional = [];

        foreach ($this->packageClasses() as $class) {
            $collaborators = $this->collaboratorParameters($class);

            if ($collaborators === []) {
                continue;
            }

            $services[] = $class;

            foreach ($collaborators as $parameter) {
                if (in_array($parameter->getName(), $nullMeansOff[$class] ?? [], true)) {
                    continue;
                }

                if ($parameter->allowsNull() || $parameter->isDefaultValueAvailable()) {
                    $optional[] = sprintf('%s::__construct($%s)', $class, $parameter->getName());
                }
            }
        }

        $this->assertSame([], $optional, 'A service constructor makes a collaborator optional.');

        // The rule has teeth only if the derivation recognises the
        // services it was written for; an empty set would pass above.
        foreach ([
            \Laraflow\Workflow\WorkflowRunner::class,
            \Laraflow\Workflow\WaitResolver::class,
            \Laraflow\Workflow\OutcomeRecorder::class,
            \Laraflow\Workflow\RecoveryService::class,
            \Laraflow\Workflow\RetentionService::class,
            \Laraflow\Queue\WorkflowDispatcher::class,
            \Laraflow\Workflow\Repositories\EloquentExecutionRepository::class,
            \Laraflow\Integration\Http\TransportPipeline::class,
            \Laraflow\Integration\Http\ConnectorObserver::class,
        ] as $known) {
            $this->assertContains($known, $services, "{$known} was not recognised as a service.");
        }
    }

    public function test_no_console_command_reaches_past_the_public_contract(): void
    {
        $offenders = [];

        foreach (File::allFiles($this->path('src/Console')) as $file) {
            $source = (string) file_get_contents($file->getRealPath());

            // The Milestone 4 promise: anything the console can show,
            // an application can show, because they read the same API.
            // Models by import or alias, the query builder by table name
            // — every way past the contract this suite knows of.
            foreach (['Workflow\\Models\\', 'Models\\', 'DB::table(', "'laraflow_"] as $reach) {
                if (str_contains($source, $reach)) {
                    $offenders[] = $file->getFilename().' ('.$reach.')';
                }
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

                // Imports and inline references both: writing
                // \Laraflow\Integration\Http\TransportPipeline in the
                // body reaches just as far into internals as importing
                // it, and should not be the way around this.
                preg_match_all('/^use (Laraflow\\\\[A-Za-z\\\\]+);/m', $source, $imports);
                preg_match_all('/\\\\(Laraflow\\\\[A-Za-z\\\\]+)/', $source, $inline);

                $referenced = array_unique(array_merge($imports[1], $inline[1]));

                foreach ($referenced as $used) {
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
     * The constructor parameters of $class that are collaborators.
     *
     * @param  class-string  $class
     * @return list<ReflectionParameter>
     */
    protected function collaboratorParameters(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $collaborators = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            // A union of classes is a collaborator by any member.
            $names = match (true) {
                $type instanceof ReflectionNamedType => $type->isBuiltin() ? [] : [$type->getName()],
                $type instanceof ReflectionUnionType => array_map(
                    fn (ReflectionType $t): string => $t instanceof ReflectionNamedType && ! $t->isBuiltin() ? $t->getName() : '',
                    $type->getTypes(),
                ),
                default => [],
            };

            foreach (array_filter($names) as $name) {
                if ($this->isCollaboratorType($name)) {
                    $collaborators[] = $parameter;

                    break;
                }
            }
        }

        return $collaborators;
    }

    protected function isCollaboratorType(string $type): bool
    {
        static $memo = [];

        // The language's own interfaces describe values, not services.
        if (in_array($type, [Throwable::class, DateTimeInterface::class, Stringable::class, Closure::class, DateTimeImmutable::class], true)) {
            return false;
        }

        if (interface_exists($type) || str_starts_with($type, 'Illuminate\\') || str_starts_with($type, 'Psr\\')) {
            return true;
        }

        // Exceptions are values the package throws, whatever they carry.
        if (! str_starts_with($type, 'Laraflow\\') || str_starts_with($type, 'Laraflow\\Exceptions\\') || ! class_exists($type) || enum_exists($type)) {
            return false;
        }

        if (array_key_exists($type, $memo)) {
            return $memo[$type];
        }

        // Cycle guard before the recursion; a class that only reaches
        // itself has no collaborator.
        $memo[$type] = false;

        return $memo[$type] = $this->collaboratorParameters($type) !== [];
    }

    /**
     * Every class, interface, and enum under src/, by file.
     *
     * @return list<class-string>
     */
    protected function packageClasses(): array
    {
        $classes = [];

        foreach (File::allFiles($this->path('src')) as $file) {
            $class = 'Laraflow\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                mb_substr($file->getRealPath(), mb_strlen($this->path('src')) + 1),
            );

            if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Every class row of docs/public-api.md with its stability level.
     *
     * @return array<class-string, string>
     */
    protected function apiDocumentClasses(): array
    {
        $rows = [];
        $doc = (string) file_get_contents($this->path('docs/public-api.md'));

        foreach (explode("\n", $doc) as $line) {
            if (preg_match('/^\|\s*`(Laraflow\\\\[A-Za-z0-9\\\\]+)`.*\|\s*(Public|Experimental|Internal)\s*\|/', $line, $match)) {
                /** @var class-string $class */
                $class = $match[1];
                $rows[$class] = $match[2];
            }
        }

        $this->assertNotEmpty($rows, 'public-api.md has stability tables; parsing them found nothing, so the format moved.');

        return $rows;
    }

    /**
     * @return list<string>
     */
    protected function documents(): array
    {
        return array_values([
            $this->path('README.md'),
            ...array_map(
                fn ($file): string => $file->getRealPath(),
                File::files($this->path('docs')),
            ),
        ]);
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
