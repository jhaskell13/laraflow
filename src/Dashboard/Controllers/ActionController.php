<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\ErrorRedactor;
use Laraflow\Support\Handoff;
use Laraflow\Support\JsonObject;
use Laraflow\Support\Outcome;
use Laraflow\Support\WorkflowName;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionReader;
use Laraflow\Workflow\RunnerOptions;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowExecution;
use Laraflow\Workflow\WorkflowResolver;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;

/**
 * @internal The four actions, each the browser form of a console
 * command and each through the same service the command calls
 * (docs/dashboard.md, "Actions"). Every one ends on the execution's
 * detail page with what became of it said the way the console says
 * it — a wait as a wait, a retry as a retry, never a parked execution
 * as "done".
 *
 * None of these routes exist when the dashboard is mounted read-only.
 */
final class ActionController
{
    /** The session key the outcome of an action is flashed under. */
    public const FLASH = 'laraflow.flash';

    public function __construct(
        private readonly ExecutionReader $repository,
        private readonly WorkflowRunner $runner,
        private readonly WorkflowDispatcher $dispatcher,
        private readonly WorkflowResolver $resolver,
        private readonly Container $container,
        private readonly ErrorRedactor $redactor,
        private readonly RunnerOptions $options,
    ) {}

    /**
     * laraflow:execute {id} {--queue} {--now}: run or recover an
     * execution in this request, or hand it to the queue.
     */
    public function execute(Request $request, int $id): RedirectResponse
    {
        try {
            $execution = $this->repository->find($id);
            // Resolved up front in both modes, as the command does: a
            // missing class should fail here, not on a worker.
            $workflow = $this->resolver->resolve($execution->id, $execution->workflow);
        } catch (WorkflowException $exception) {
            return $this->toExecution($request, $id, Outcome::ERROR, $exception->getMessage());
        }

        if ($request->boolean('queue')) {
            return $this->dispatch($request, $execution);
        }

        try {
            $result = $this->runner->execute($id, $workflow, ignoreRetrySchedule: $request->boolean('now'));
        } catch (WorkflowException $exception) {
            return $this->toExecution($request, $id, Outcome::ERROR, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->toExecution(
                $request,
                $id,
                Outcome::ERROR,
                'A step failed: '.$this->redacted($exception).' The failure is persisted; recover from the checkpoint by executing again.',
            );
        }

        return $this->toOutcome($request, $result);
    }

    /**
     * laraflow:cancel {id} {--reason=}.
     */
    public function cancel(Request $request, int $id): RedirectResponse
    {
        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        try {
            $result = $this->runner->cancel($id, $reason);
        } catch (WorkflowException $exception) {
            return $this->toExecution($request, $id, Outcome::ERROR, $exception->getMessage());
        }

        if ($result->isCancelled()) {
            return $this->toExecution($request, $id, Outcome::INFO, 'The execution is cancelled; completed steps are kept.');
        }

        return $this->toExecution(
            $request,
            $id,
            Outcome::INFO,
            'Cancellation requested. The worker running this execution stops at its next step boundary.',
        );
    }

    /**
     * WorkflowRunner::signal(): record a signal for a waiting execution,
     * and deliver it when asked. Recording is not delivery — the queue
     * fast path and laraflow:recover are (docs/advanced-workflows.md) —
     * so the form offers to dispatch it, and says which it did.
     */
    public function signal(Request $request, int $id): RedirectResponse
    {
        $name = $request->input('signal');
        $name = is_string($name) ? trim($name) : '';

        try {
            $payload = JsonObject::parse($this->string($request, 'payload'), 'signal payload');
            $this->runner->signal($id, $name, $payload);
        } catch (InvalidArgumentException|WorkflowException $exception) {
            return $this->toExecution($request, $id, Outcome::ERROR, $exception->getMessage());
        }

        if (! $request->boolean('deliver')) {
            return $this->toExecution(
                $request,
                $id,
                Outcome::INFO,
                "Signal [{$name}] recorded. The execution is due now; laraflow:recover, or a worker, delivers it.",
            );
        }

        // Delivered through Handoff, as every other dispatch is: a sync
        // connection runs the job inside the dispatch call, so a throw
        // here may be a broker refusing the push *or* a step failing
        // inline, and only the record can tell which (Milestone 10
        // audit — this used to say "could not be dispatched" for a step
        // that had in fact run and failed).
        try {
            $before = $this->repository->find($id);
        } catch (WorkflowException $exception) {
            return $this->toExecution($request, $id, Outcome::ERROR, $exception->getMessage());
        }

        $handoff = Handoff::attempt($this->dispatcher, $this->repository, $before);

        return match ($handoff->outcome) {
            Handoff::QUEUED => $this->toExecution(
                $request,
                $id,
                Outcome::INFO,
                "Signal [{$name}] recorded and dispatched; a worker will resume the execution.",
            ),
            Handoff::RAN => $this->toOutcome($request, $handoff->execution),
            Handoff::STEP_FAILED => $this->toExecution(
                $request,
                $id,
                Outcome::ERROR,
                "Signal [{$name}] delivered, and the queue connection ran the job in this process (sync driver): "
                .'a step failed: '.$this->redacted($handoff->exception)
                .' The failure is persisted; recover from the checkpoint by executing again.',
            ),
            // Refused or not pushed: the signal is recorded either way,
            // and recover delivers what is recorded.
            default => $this->toExecution(
                $request,
                $id,
                Outcome::WARN,
                "Signal [{$name}] recorded, but it could not be dispatched: ".$this->redacted($handoff->exception)
                .' laraflow:recover delivers it.',
            ),
        };
    }

    /**
     * laraflow:start {workflow} {--input=} {--queue} {--correlation=}
     * {--tenant=}: the same resolution and validation, then the same
     * services. Nothing is persisted until every field has been read.
     */
    public function start(Request $request): RedirectResponse
    {
        try {
            $class = WorkflowName::resolve($this->string($request, 'workflow') ?? '', $this->container);
            $input = JsonObject::parse($this->string($request, 'input'), 'input');
            $options = new ExecutionOptions(
                correlationId: $this->string($request, 'correlation'),
                tenantId: $this->string($request, 'tenant'),
            );
        } catch (InvalidArgumentException|WorkflowException $exception) {
            return $this->backToStartForm($request, $exception->getMessage());
        }

        try {
            $workflow = $this->container->make($class);
        } catch (Throwable $exception) {
            return $this->backToStartForm($request, "[{$class}] could not be resolved from the container: ".$exception->getMessage());
        }

        if (! $workflow instanceof Workflow) {
            return $this->backToStartForm($request, "The container resolved [{$class}] to something that is not a ".Workflow::class.'.');
        }

        try {
            $execution = $this->runner->start($workflow, $input, $options);
        } catch (WorkflowException $exception) {
            return $this->backToStartForm($request, $exception->getMessage());
        }

        if ($request->boolean('queue')) {
            return $this->dispatch($request, $execution);
        }

        try {
            $result = $this->runner->execute($execution->id, $workflow);
        } catch (WorkflowException $exception) {
            return $this->toExecution($request, $execution->id, Outcome::ERROR, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->toExecution(
                $request,
                $execution->id,
                Outcome::ERROR,
                'A step failed: '.$this->redacted($exception).' The failure is persisted; recover from the checkpoint by executing again.',
            );
        }

        return $this->toOutcome($request, $result);
    }

    /**
     * Hand an execution to the queue. What became of it is Handoff's
     * decision, shared with the console; this only flashes it.
     */
    private function dispatch(Request $request, WorkflowExecution $before): RedirectResponse
    {
        $handoff = Handoff::attempt($this->dispatcher, $this->repository, $before);

        // Handoff's step-failed message carries the raw exception text,
        // which the console may print but a flash — rendered to whoever
        // holds the session, and kept in it — must not (SECURITY.md).
        // The same sentence, redacted.
        if ($handoff->outcome === Handoff::STEP_FAILED) {
            return $this->toExecution(
                $request,
                $before->id,
                $handoff->level,
                'The queue connection ran the job in this process (sync driver) and a step failed: '
                .$this->redacted($handoff->exception),
            );
        }

        return $this->toExecution($request, $before->id, $handoff->level, $handoff->message);
    }

    /**
     * An exception's message as Laraflow may show it: through the
     * configured ErrorRedactor and truncated to
     * laraflow.execution.max_error_length — the form the execution row
     * already holds. A step's exception is the application's text and
     * may carry a secret; the flash is rendered to a browser, so it gets
     * what the record gets and nothing more.
     */
    private function redacted(?Throwable $exception): string
    {
        if ($exception === null) {
            return '';
        }

        return mb_substr($this->redactor->redact($exception, $exception->getMessage()), 0, $this->options->maxErrorLength);
    }

    private function toOutcome(Request $request, WorkflowExecution $execution): RedirectResponse
    {
        ['level' => $level, 'message' => $message] = Outcome::describe($execution);

        return $this->toExecution($request, $execution->id, $level, $message);
    }

    private function toExecution(Request $request, int $id, string $level, string $message): RedirectResponse
    {
        $this->flash($request, $level, $message);

        return new RedirectResponse(route('laraflow.executions.show', $id));
    }

    private function backToStartForm(Request $request, string $message): RedirectResponse
    {
        $this->flash($request, Outcome::ERROR, $message);

        $response = new RedirectResponse(route('laraflow.executions', ['start' => 1]));

        if ($request->hasSession()) {
            // The form is shown back filled in, as a validation failure
            // leaves any Laravel form.
            $request->session()->flashInput($request->only(['workflow', 'input', 'correlation', 'tenant', 'queue']));
        }

        return $response;
    }

    /**
     * The actions run behind whatever middleware the application
     * mounted; the session is where the outcome waits for the next
     * page. Without one — a bare mount — the redirect still lands on
     * the record, which says what it is.
     */
    private function flash(Request $request, string $level, string $message): void
    {
        if ($request->hasSession()) {
            $request->session()->flash(self::FLASH, ['level' => $level, 'message' => $message]);
        }
    }

    private function string(Request $request, string $name): ?string
    {
        $value = $request->input($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
