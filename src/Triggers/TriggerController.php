<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laraflow\Exceptions\TriggerRefusedException;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Exceptions\Workflow\InvalidSignalException;
use Laraflow\Exceptions\Workflow\PayloadTooLargeException;
use Laraflow\Exceptions\Workflow\SignalRefusedException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Support\Handoff;
use Laraflow\Support\JsonObject;
use Laraflow\Support\SafeIdentifier;
use Laraflow\Workflow\ExecutionReader;
use Laraflow\Workflow\WorkflowRunner;
use LogicException;
use Throwable;

/**
 * @internal The two trigger endpoints. Both end in deliver(): the same
 * `signal()` and the same `Handoff` the console and the dashboard use,
 * answered in the runtime's own words. No privileged path.
 */
final class TriggerController
{
    /**
     * How much of a refusal's wording a response carries. The runtime's
     * own messages are short; the bound is there so a message that
     * quotes the request cannot be used to echo a large body back.
     */
    private const MAX_MESSAGE_BYTES = 1024;

    public function __construct(
        private WorkflowRunner $runner,
        private WorkflowDispatcher $dispatcher,
        private ExecutionReader $repository,
        private TriggerRegistry $registry,
        private TriggerReporter $reporter,
        private Container $container,
    ) {}

    /**
     * The signed endpoint. VerifyTriggerSignature has already run: the
     * request is genuine, and this is the first thing that reads the
     * database.
     */
    public function signal(Request $request, int $id, string $signal): JsonResponse
    {
        try {
            $payload = JsonObject::parse($request->getContent(), 'signal payload');
        } catch (InvalidArgumentException $exception) {
            return $this->refusal(VerifyTriggerSignature::TRIGGER, $id, $signal, TriggerRefusalReason::InvalidPayload, 422, 'invalid', $exception->getMessage());
        }

        return $this->deliver(VerifyTriggerSignature::TRIGGER, new SignalIntent($id, $signal, $payload));
    }

    /**
     * A named trigger: the provider's verification, the application's
     * resolution, then the shared path.
     */
    public function named(Request $request, string $trigger): JsonResponse
    {
        $definition = $this->registry->find($trigger);

        if ($definition === null) {
            return new JsonResponse([
                'status' => 'not_found',
                'trigger' => $trigger,
                'message' => "No trigger named [{$trigger}] is registered.",
            ], 404);
        }

        // The body limit before the provider's verifier, as before the
        // signed endpoint's: a verifier hashes the body too.
        if (VerifyTriggerSignature::oversized($request, $this->container->make(Config::class))) {
            $this->reporter->refused($trigger, TriggerRefusalReason::BodyTooLarge, null, null);

            return VerifyTriggerSignature::tooLarge();
        }

        $verifier = $this->container->make($definition->verifier);

        if (! $verifier instanceof TriggerVerifier) {
            // The registry checked the class; this checks what the
            // container bound to it. Misconfiguration is loud.
            throw new LogicException("The container resolved [{$definition->verifier}] to something that is not a ".TriggerVerifier::class.'.');
        }

        try {
            $verifier->verify($request);
        } catch (TriggerRefusedException $exception) {
            $this->reporter->refused($trigger, $exception->reason, null, null);

            return new JsonResponse(['status' => 'refused'], 401);
        }

        try {
            $intent = ($definition->resolver)($request);
        } catch (Throwable $exception) {
            return $this->resolverFailed($trigger, $exception);
        }

        if ($intent === null) {
            return new JsonResponse(['status' => 'ignored', 'trigger' => $trigger], 200);
        }

        if (! $intent instanceof SignalIntent) {
            return $this->resolverFailed($trigger, new LogicException(
                'A trigger resolver must return a '.SignalIntent::class.' or null, got '.get_debug_type($intent).'.'
            ));
        }

        return $this->deliver($trigger, $intent);
    }

    /**
     * Record the signal and attempt delivery — the dashboard's signal
     * action, answered as JSON. Existence is checked explicitly so an
     * unknown execution is `404` rather than the `409` the signal
     * primitive would give it, and checked only here, after
     * verification, so an unsigned caller cannot enumerate.
     */
    private function deliver(string $trigger, SignalIntent $intent): JsonResponse
    {
        try {
            $this->repository->find($intent->executionId);
            $receipt = $this->runner->recordSignal($intent->executionId, $intent->signal, $intent->payload);
        } catch (ExecutionNotFoundException $exception) {
            return $this->refusal($trigger, $intent->executionId, $intent->signal, TriggerRefusalReason::UnknownExecution, 404, 'not_found', $exception->getMessage());
        } catch (SignalRefusedException $exception) {
            return $this->refusal($trigger, $intent->executionId, $intent->signal, TriggerRefusalReason::NotWaiting, 409, 'not_waiting', $exception->getMessage());
        } catch (PayloadTooLargeException $exception) {
            return $this->refusal($trigger, $intent->executionId, $intent->signal, TriggerRefusalReason::TooLarge, 413, 'too_large', $exception->getMessage());
        } catch (InvalidSignalException|UnserializableValueException $exception) {
            return $this->refusal($trigger, $intent->executionId, $intent->signal, TriggerRefusalReason::InvalidPayload, 422, 'invalid', $exception->getMessage());
        }

        $execution = $receipt->execution;

        $this->reporter->accepted($trigger, $execution->id, $intent->signal);

        // Buffered in the inbox: nothing became due, so nothing is
        // handed on; the step that waits on the name takes it.
        if ($receipt->buffered) {
            return new JsonResponse([
                'status' => 'recorded',
                'trigger' => $trigger,
                'execution' => $execution->id,
                'signal' => $intent->signal,
                'delivery' => 'buffered',
                'message' => 'The execution is not waiting on this signal yet; it is kept until a step waits on it.',
            ], 202);
        }

        $handoff = Handoff::attempt($this->dispatcher, $this->repository, $execution);

        // Fixed sentences only: Handoff's own message may quote a step's
        // or a broker's exception, and a provider keeps response bodies —
        // nothing the redactor never saw goes to a third party. What
        // became of the run is on the record, and `execution_status`
        // says it.
        [$delivery, $message] = match ($handoff->outcome) {
            Handoff::QUEUED => ['queued', null],
            Handoff::RAN, Handoff::STEP_FAILED => ['ran', null],
            // Refused or the push failed: recorded, and laraflow:recover
            // is the contractual delivery.
            default => ['pending', 'The signal is recorded; laraflow:recover delivers the execution.'],
        };

        $body = [
            'status' => 'recorded',
            'trigger' => $trigger,
            'execution' => $execution->id,
            'signal' => $intent->signal,
            'delivery' => $delivery,
        ];

        if ($delivery === 'ran') {
            $body['execution_status'] = $handoff->execution->status->value;
        }

        if ($message !== null) {
            $body['message'] = $message;
        }

        return new JsonResponse($body, 202);
    }

    private function refusal(string $trigger, int $executionId, string $signal, TriggerRefusalReason $reason, int $status, string $label, string $message): JsonResponse
    {
        $this->reporter->refused($trigger, $reason, $executionId, $signal);

        // The signal came off a URL and the message can quote it, so
        // both are made presentable before they go into a response.
        // Unbounded they would echo whatever was sent; malformed UTF-8
        // would make JsonResponse throw, turning a clean refusal into a
        // 500 with a rendered stack trace (Milestone 12 security
        // review). The log has had this treatment since Milestone 10.
        return new JsonResponse([
            'status' => $label,
            'trigger' => $trigger,
            'execution' => $executionId,
            'signal' => SafeIdentifier::presentable($signal),
            'message' => SafeIdentifier::presentable($message, self::MAX_MESSAGE_BYTES),
        ], $status);
    }

    private function resolverFailed(string $trigger, Throwable $exception): JsonResponse
    {
        $this->reporter->resolverFailed($trigger, $exception);

        return new JsonResponse([
            'status' => 'error',
            'trigger' => $trigger,
            'message' => 'The trigger\'s resolver failed; the application log has the exception class.',
        ], 500);
    }
}
