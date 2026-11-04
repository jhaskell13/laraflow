<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

use Laraflow\Events\EventEmitter;
use Laraflow\Events\TriggerAccepted;
use Laraflow\Events\TriggerRefused;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal The one place a trigger's outcome is announced — the log
 * line and the event, with the same fields, and never a payload, a
 * header, a key, or a client address. The middleware and the
 * controller both report here, so a refusal is worded once.
 */
final class TriggerReporter
{
    public function __construct(
        private EventEmitter $events,
        private LoggerInterface $logger,
    ) {}

    public function refused(string $trigger, TriggerRefusalReason $reason, ?int $executionId, ?string $signal): void
    {
        // The signal came off an unverified URL: bounded and stripped of
        // control bytes before it reaches a log line or a listener,
        // because nothing upstream has validated it yet.
        $signal = $signal === null ? null : self::presentable($signal);

        $this->logger->warning('Laraflow refused a trigger request.', [
            'trigger' => $trigger,
            'reason' => $reason->value,
            'execution_id' => $executionId,
            'signal' => $signal,
        ]);

        $this->events->emit(new TriggerRefused($trigger, $reason, $executionId, $signal));
    }

    public function accepted(string $trigger, int $executionId, string $signal): void
    {
        $this->logger->info('Laraflow recorded a signal from a trigger.', [
            'trigger' => $trigger,
            'execution_id' => $executionId,
            'signal' => $signal,
        ]);

        $this->events->emit(new TriggerAccepted($trigger, $executionId, $signal));
    }

    /**
     * The application's own mapping failed: its class, never its
     * message, because a resolver's exception may quote the body it
     * was reading.
     */
    public function resolverFailed(string $trigger, Throwable $exception): void
    {
        $this->logger->error('Laraflow could not resolve a trigger request: the resolver threw.', [
            'trigger' => $trigger,
            'error_class' => $exception::class,
        ]);

        $this->events->emit(new TriggerRefused($trigger, TriggerRefusalReason::ResolverFailed, null, null));
    }

    /**
     * Evidence a rotation can be finished on: which previous key is
     * still in use.
     */
    public function verifiedWithPreviousKey(string $trigger, int $index, int $executionId, string $signal): void
    {
        $this->logger->debug('Laraflow verified a trigger request with a previous key.', [
            'trigger' => $trigger,
            'previous_key_index' => $index,
            'execution_id' => $executionId,
            'signal' => $signal,
        ]);
    }

    /**
     * A refused request's signal, made safe to say: at most 255 bytes,
     * control bytes dropped. Byte-level on purpose — invalid UTF-8 must
     * not make the sanitiser the thing that fails.
     */
    private static function presentable(string $signal): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', substr($signal, 0, 255));
    }
}
