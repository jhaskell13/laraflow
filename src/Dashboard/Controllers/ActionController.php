<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\JsonObject;
use Laraflow\Support\Outcome;
use Laraflow\Support\WorkflowName;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\ExecutionRepository;
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
        private readonly ExecutionRepository $repository,
        private readonly WorkflowRunner $runner,
        private readonly WorkflowDispatcher $dispatcher,
        private readonly WorkflowResolver $resolver,
        private readonly Container $container,
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
                'A step failed: '.$exception->getMessage().' The failure is persisted; recover from the checkpoint by executing again.',
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

        try {
            $before = $this->repository->find($id);
            $this->dispatcher->dispatchExecution($id);
        } catch (Throwable $exception) {
            return $this->toExecution(
                $request,
                $id,
                Outcome::WARN,
                "Signal [{$name}] recorded, but it could not be dispatched: ".$exception->getMessage()
                .' laraflow:recover delivers it.',
            );
        }

        $after = $this->repository->find($id);

        if (Outcome::ranInline($before, $after)) {
            return $this->toOutcome($request, $after);
        }

        return $this->toExecution($request, $id, Outcome::INFO, "Signal [{$name}] recorded and dispatched; a worker will resume the execution.");
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
                'A step failed: '.$exception->getMessage().' The failure is persisted; recover from the checkpoint by executing again.',
            );
        }

        return $this->toOutcome($request, $result);
    }

    /**
     * Hand an execution to the queue, and say what actually happened:
     * a sync connection runs the job inside the dispatch call, so the
     * record is read back rather than the configuration consulted
     * (the Phase C audit's first and third findings).
     */
    private function dispatch(Request $request, WorkflowExecution $before): RedirectResponse
    {
        try {
            $this->dispatcher->dispatchExecution($before->id);
        } catch (WorkflowException $exception) {
            return $this->toExecution($request, $before->id, Outcome::ERROR, $exception->getMessage());
        } catch (Throwable $exception) {
            $after = $this->repository->find($before->id);

            if (! Outcome::ranInline($before, $after)) {
                return $this->toExecution(
                    $request,
                    $before->id,
                    Outcome::ERROR,
                    'The execution could not be queued: '.$exception->getMessage().' It is persisted; run it here instead.',
                );
            }

            return $this->toExecution(
                $request,
                $before->id,
                Outcome::ERROR,
                'The queue connection ran the job in this process (sync driver) and a step failed: '.$exception->getMessage(),
            );
        }

        $after = $this->repository->find($before->id);

        if (Outcome::ranInline($before, $after)) {
            return $this->toOutcome($request, $after);
        }

        return $this->toExecution($request, $before->id, Outcome::INFO, 'Queued. A worker will pick it up; this page shows its progress.');
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
