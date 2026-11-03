<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

/**
 * @experimental Triggers are new in Milestone 10; see docs/triggers.md.
 *
 * What a named trigger's resolver turns a verified provider request
 * into: the one signal to deliver. Everything after this is the signed
 * endpoint's own path — the same `signal()`, the same delivery, the
 * same answers.
 */
final readonly class SignalIntent
{
    /**
     * @param  array<array-key, mixed>  $payload  The signal's payload: the waiting step's output,
     *                                            and the context value under the signal's name.
     */
    public function __construct(
        public int $executionId,
        public string $signal,
        public array $payload = [],
    ) {}
}
