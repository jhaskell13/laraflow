<?php

declare(strict_types=1);

namespace Laraflow\Events;

use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @internal Enabled by laraflow.logging.lifecycle; not a public API.
 *
 * Writes one log record per lifecycle event, for applications that want
 * an execution trail in their logs without writing listeners.
 *
 * Registered as a wildcard listener on `Laraflow\Events\*`, so an event
 * class added in a later version is logged without this class being
 * touched. The record's context is the event's own public properties,
 * which is safe by construction: lifecycle events carry identifiers and
 * metadata only — never workflow input, step output, or context — and
 * any error message they carry has already passed through the
 * configured redactor.
 *
 * Off by default. An application that already listens to these events,
 * or that ships them to a metrics backend instead, should not also pay
 * for a log line per step.
 */
class LifecycleLogger
{
    public function __construct(
        protected LoggerInterface $logger,
    ) {}

    /**
     * @param  array<int, object>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! is_object($event)) {
            return;
        }

        $this->logger->log(
            $this->levelFor($event),
            'Laraflow: '.class_basename($event),
            $this->contextFor($event),
        );
    }

    /**
     * A terminal failure is an error; a failure that will be retried is
     * a warning, because the execution has not failed — matching the
     * guidance to alert on WorkflowExecutionFailed rather than on every
     * failed attempt.
     */
    protected function levelFor(object $event): string
    {
        return match (true) {
            $event instanceof WorkflowExecutionFailed => LogLevel::ERROR,
            $event instanceof WorkflowStepFailed,
            $event instanceof WorkflowStepRetryScheduled,
            $event instanceof WorkflowExecutionRetryScheduled => LogLevel::WARNING,
            default => LogLevel::INFO,
        };
    }

    /**
     * The event's public properties, nulls dropped. Datetimes are
     * formatted rather than passed as objects, which a JSON log
     * formatter would otherwise render inconsistently.
     *
     * @return array<string, mixed>
     */
    protected function contextFor(object $event): array
    {
        $context = [];

        foreach (get_object_vars($event) as $key => $value) {
            if ($value === null) {
                continue;
            }

            $context[$key] = $value instanceof DateTimeInterface
                ? $value->format(DATE_ATOM)
                : $value;
        }

        return $context;
    }
}
