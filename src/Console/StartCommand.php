<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\StartsExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\JsonObject;
use Laraflow\Support\WorkflowName;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionReader;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @internal The command class is implementation. What 1.0 freezes is the
 * command's name, its options, and the shape of its `--json` document —
 * see docs/public-api.md. Nothing should type-hint or extend this class.
 *
 * Starts an execution of any workflow class, with JSON input, inline or
 * on the queue. The primitive the install story needs once the
 * generators have written a workflow: nothing else runs a class that
 * did not ship with the package without writing code first.
 */
final class StartCommand extends Command
{
    use StartsExecutions;

    protected $signature = 'laraflow:start
        {workflow : The workflow class — fully qualified, or a bare name under the application\'s Workflows namespace}
        {--input= : A JSON object that seeds the context, e.g. --input=\'{"order_id": 42}\'}
        {--queue : Dispatch the execution to the queue instead of running it in this process}
        {--correlation= : A correlation id to record on the execution}
        {--tenant= : A tenant id to record on the execution}
        {--json : Print one JSON document instead of the report}';

    protected $description = 'Start an execution of a workflow class, inline or on the queue';

    public function handle(
        WorkflowRunner $runner,
        ExecutionReader $repository,
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
            return $this->refuse($exception->getMessage());
        }

        try {
            $workflow = $this->laravel->make($class);
        } catch (Throwable $exception) {
            // A workflow with a constructor dependency the container
            // cannot build is a wiring fault, not a workflow fault.
            return $this->refuse("[{$class}] could not be resolved from the container: ".$exception->getMessage());
        }

        // class_exists() and is_subclass_of() above hold for the name;
        // this holds for what the container chose to bind to it.
        if (! $workflow instanceof Workflow) {
            return $this->refuse(
                "The container resolved [{$class}] to something that is not a ".Workflow::class
                .'. Check the binding for it in a service provider.'
            );
        }

        if (! $this->wantsJson()) {
            $this->components->info('Starting '.$workflow::class);
        }

        try {
            if ((bool) $this->option('queue')) {
                return $this->startQueued($runner, $repository, $dispatcher, $workflow, $input, $options);
            }

            return $this->startInline($runner, $repository, $workflow, $input, $options);
        } catch (WorkflowException $exception) {
            // start() refusing the plan or the input: nothing was
            // persisted, and the message names what to fix.
            return $this->refuse($exception->getMessage());
        }
    }

    /**
     * @return class-string<Workflow>
     *
     * @throws InvalidArgumentException when nothing by that name is a workflow.
     */
    protected function workflowClass(): string
    {
        return WorkflowName::resolve($this->argument('workflow'), $this->laravel);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the option is not a JSON object.
     */
    protected function inputOption(): array
    {
        return JsonObject::parse($this->stringOption('input'), '--input option');
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

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
