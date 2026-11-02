<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Laraflow\Console\Concerns\StartsExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowRunner;
use RuntimeException;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Starts an execution of any workflow class, with JSON input, inline or
 * on the queue. The primitive the install story needs once the
 * generators have written a workflow: nothing else runs a class that
 * did not ship with the package without writing code first.
 */
class StartCommand extends Command
{
    use StartsExecutions;

    protected $signature = 'laraflow:start
        {workflow : The workflow class — fully qualified, or a bare name under the application\'s Workflows namespace}
        {--input= : A JSON object that seeds the context, e.g. --input=\'{"order_id": 42}\'}
        {--queue : Dispatch the execution to the queue instead of running it in this process}
        {--correlation= : A correlation id to record on the execution}
        {--tenant= : A tenant id to record on the execution}';

    protected $description = 'Start an execution of a workflow class, inline or on the queue';

    public function handle(
        WorkflowRunner $runner,
        ExecutionRepository $repository,
        WorkflowDispatcher $dispatcher,
    ): int {
        try {
            $class = $this->workflowClass();
            $input = $this->inputOption();
            $options = new ExecutionOptions(
                correlationId: $this->stringOption('correlation'),
                tenantId: $this->stringOption('tenant'),
            );
        } catch (InvalidArgumentException|WorkflowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $workflow = $this->laravel->make($class);
        } catch (Throwable $exception) {
            // A workflow with a constructor dependency the container
            // cannot build is a wiring fault, not a workflow fault.
            $this->components->error("[{$class}] could not be resolved from the container: ".$exception->getMessage());

            return self::FAILURE;
        }

        // class_exists() and is_subclass_of() above hold for the name;
        // this holds for what the container chose to bind to it.
        if (! $workflow instanceof Workflow) {
            $this->components->error("The container resolved [{$class}] to something that is not a ".Workflow::class.'.');

            return self::FAILURE;
        }

        $this->components->info('Starting '.$workflow::class);

        try {
            if ((bool) $this->option('queue')) {
                return $this->startQueued($runner, $repository, $dispatcher, $workflow, $input, $options);
            }

            return $this->startInline($runner, $repository, $workflow, $input, $options);
        } catch (WorkflowException $exception) {
            // start() refusing the plan or the input: nothing was
            // persisted, and the message names what to fix.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * The class the argument names, resolved and checked by name.
     *
     * Forward slashes are accepted as namespace separators, as the
     * generators accept them, because a backslash costs quoting in
     * every shell; a bare name is looked for where `make:workflow`
     * writes, so the class the generator just wrote is reachable by
     * the name it was given.
     *
     * @return class-string<Workflow>
     *
     * @throws InvalidArgumentException when nothing by that name is a workflow.
     */
    protected function workflowClass(): string
    {
        $name = trim(str_replace('/', '\\', $this->argument('workflow')), " \t\n\r\0\x0B\\");

        if ($name === '') {
            throw new InvalidArgumentException('The workflow argument must name a workflow class.');
        }

        $candidates = [$name];

        if (! str_contains($name, '\\')) {
            $candidates[] = $this->workflowsNamespace().$name;
        }

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
            count($candidates) > 1 ? ' (also looked for '.$candidates[1].')' : '',
        ));
    }

    /**
     * Where `make:workflow` writes: the application namespace plus
     * `Workflows`. Outside a Laravel application skeleton the namespace
     * cannot be read from composer.json, and `App\` is the answer the
     * generators would give there too.
     */
    protected function workflowsNamespace(): string
    {
        try {
            $namespace = $this->laravel->getNamespace();
        } catch (RuntimeException) {
            $namespace = 'App\\';
        }

        return $namespace.'Workflows\\';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the option is not a JSON object.
     */
    protected function inputOption(): array
    {
        $raw = $this->stringOption('input');

        if ($raw === null) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The --input option must be valid JSON: '.$exception->getMessage().'.');
        }

        // Input seeds the context by key, so only an object will do.
        // `[]` decodes to the same empty array `{}` does and is accepted
        // as "no input"; a non-empty list has no keys to seed with.
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException(
                'The --input option must be a JSON object, e.g. --input=\'{"order_id": 42}\'; got '
                .(is_array($decoded) ? 'a list' : get_debug_type($decoded)).'.'
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * An option as a string, with blank meaning absent — `--tenant=`
     * and no `--tenant` are the same request.
     */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : $value;
    }
}
