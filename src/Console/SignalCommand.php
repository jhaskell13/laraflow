<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\ParsesExecutionId;
use Laraflow\Console\Concerns\StartsExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Handoff;
use Laraflow\Support\JsonObject;
use Laraflow\Support\JsonReport;
use Laraflow\Workflow\ExecutionReader;
use Laraflow\Workflow\WorkflowRunner;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Delivers a signal to a waiting execution from the terminal — the
 * console form of the dashboard's signal action and of a trigger
 * request, through the same WorkflowRunner::signal() and the same
 * Handoff. Recording is not delivery: without --deliver the execution
 * is due now and laraflow:recover or a worker resumes it; with it, the
 * execution is handed to the queue in the same breath.
 */
final class SignalCommand extends Command
{
    use ParsesExecutionId;
    use StartsExecutions;

    protected $signature = 'laraflow:signal
        {execution : The id of the waiting execution}
        {signal : The signal name the execution is parked on}
        {--payload= : A JSON object carried into the context under the signal\'s name, e.g. --payload=\'{"approved_by": "ops"}\'}
        {--deliver : Hand the execution to the queue once the signal is recorded, instead of leaving delivery to laraflow:recover}
        {--json : Print one JSON document instead of the report}';

    protected $description = 'Record a signal for a waiting execution, and optionally deliver it';

    public function handle(WorkflowRunner $runner, ExecutionReader $repository, WorkflowDispatcher $dispatcher): int
    {
        $signal = trim((string) $this->argument('signal'));

        try {
            $id = $this->executionIdArgument();
            $payload = JsonObject::parse($this->payloadOption(), '--payload option');
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage(), ['signal' => $signal]);
        }

        try {
            $execution = $runner->signal($id, $signal, $payload);
        } catch (WorkflowException $exception) {
            // Not waiting on it, a reserved or over-long name, a payload
            // past the limit: the runtime's words, as every caller
            // prints them.
            return $this->refuse($exception->getMessage(), ['execution' => $id, 'signal' => $signal]);
        }

        if (! (bool) $this->option('deliver')) {
            $message = "Signal [{$signal}] recorded. Execution [{$id}] is due now; laraflow:recover, or a worker, delivers it. "
                ."Or deliver it from here with: laraflow:signal {$id} {$signal} --deliver";

            return $this->respond([
                'execution' => JsonReport::execution($execution),
                'signal' => $signal,
                'handoff' => null,
                'message' => $message,
            ], fn () => $this->components->info($message));
        }

        return $this->reportHandoff(
            Handoff::attempt($dispatcher, $repository, $execution),
            $repository,
            ['signal' => $signal],
        );
    }

    protected function payloadOption(): ?string
    {
        $value = $this->option('payload');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
