<?php

declare(strict_types=1);

namespace Laraflow\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Emits Laraflow lifecycle events under one policy: events are
 * observational. Each is fired strictly after the durable write it
 * describes, and a listener that throws is logged rather than allowed to
 * alter execution state, abort a dispatch, or mask a workflow failure.
 *
 * This deliberately differs from Laravel's default synchronous-listener
 * behaviour. Listeners whose failure should be retried or surfaced must
 * be queued listeners, which Laravel handles independently.
 */
class EventEmitter
{
    public function __construct(
        protected ?Dispatcher $events,
        protected LoggerInterface $logger,
    ) {}

    public function emit(object $event): void
    {
        if ($this->events === null) {
            return;
        }

        try {
            $this->events->dispatch($event);
        } catch (Throwable $listenerFailure) {
            $this->logger->error('A Laraflow lifecycle event listener threw.', [
                'event' => $event::class,
                'listener_exception' => $listenerFailure::class.': '.$listenerFailure->getMessage(),
            ]);
        }
    }
}
