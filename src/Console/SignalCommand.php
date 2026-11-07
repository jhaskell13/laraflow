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
 * @internal The command class is implementation. What 1.0 freezes is the
 * command's name, its options, and the shape of its `--json` document —
 * see docs/public-api.md. Nothing should type-hint or extend this class.
 *
 * Delivers a signal to a waiting execution from the terminal — the
 * console form of the dashboard's signal action and of a trigger
 * request, through the same WorkflowRunner::signal() and the same
 * Handoff. Recording is not delivery: without --deliver a parked
 * execution is due now and laraflow:recover or a worker resumes it;
 * with it, the execution is handed to the queue in the same breath. A
 * signal for an execution not yet parked on the name is buffered in its
 * inbox, and there is nothing to deliver until a step waits on it.
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
            $receipt = $runner->recordSignal($id, $signal, $payload);
        } catch (WorkflowException $exception) {
            // Not waiting on it, a reserved or over-long name, a payload
            // past the limit: the runtime's words, as every caller
            // prints them.
            return $this->refuse($exception->getMessage(), ['execution' => $id, 'signal' => $signal]);
        }

        $execution = $receipt->execution;
        $recorded = $receipt->buffered ? 'buffered' : 'resumed';

        if ($receipt->buffered || ! (bool) $this->option('deliver')) {
            // A buffered signal made nothing due: the step that waits on
            // the name consumes it when it parks, so --deliver has
            // nothing to hand on.
            $message = $receipt->buffered
                ? "Signal [{$signal}] buffered. Execution [{$id}] is not waiting on it yet; "
                    .'the step that waits on it takes it when it parks, and nothing is delivered until then.'
                : "Signal [{$signal}] recorded. Execution [{$id}] is due now; laraflow:recover, or a worker, delivers it. "
                    ."Or deliver it from here with: laraflow:signal {$id} {$signal} --deliver";

            return $this->respond([
                'execution' => JsonReport::execution($execution),
                'signal' => $signal,
                'recorded' => $recorded,
                'handoff' => null,
                'message' => $message,
            ], fn () => $this->components->info($message));
        }

        return $this->reportHandoff(
            Handoff::attempt($dispatcher, $repository, $execution),
            $repository,
            ['signal' => $signal, 'recorded' => $recorded],
        );
    }

    protected function payloadOption(): ?string
    {
        $value = $this->option('payload');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
