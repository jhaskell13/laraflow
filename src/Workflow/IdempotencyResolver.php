<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Closure;
use Laraflow\Exceptions\IndicatesUncertainOutcome;
use Laraflow\Exceptions\Workflow\IdempotentOutcomeUncertainException;
use Laraflow\Exceptions\Workflow\InvalidIdempotencyKeyException;
use Laraflow\Exceptions\Workflow\TooManyIdempotencyKeysException;
use Laraflow\Support\DurableValue;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * The state machine behind WorkflowContext::remember(): what finding a
 * record means, and what to write when the work ends. Kept in one
 * place so a custom IdempotencyStore supplies storage and nothing
 * else — the store cannot disagree with the contract about what a
 * pending record is.
 *
 * Semantics: docs/idempotency.md.
 */
final class IdempotencyResolver
{
    /** The scoped key fits the column it is stored in. */
    public const MAX_KEY_LENGTH = 255;

    public function __construct(
        private IdempotencyStore $store,
        private ClockInterface $clock,
        private RunnerOptions $options,
    ) {}

    /**
     * Run $work at most once per execution under $key.
     *
     * @param  Closure(): mixed  $work
     * @param  (Closure(): mixed)|null  $reconcile  Consulted only when a previous attempt began
     *                                              the work and never recorded an outcome.
     *
     * @throws InvalidIdempotencyKeyException
     * @throws TooManyIdempotencyKeysException
     * @throws IdempotentOutcomeUncertainException when the outcome is unknown and no reconciler was given.
     */
    public function remember(
        int $executionId,
        int $stepPosition,
        string $step,
        string $key,
        Closure $work,
        ?Closure $reconcile = null,
    ): mixed {
        $scoped = $this->scope($executionId, $stepPosition, $key);

        // Two passes at most: a failed record is released and begun
        // again, and the second begin() may itself find a record a
        // concurrent attempt created in between.
        for ($pass = 0; $pass < 2; $pass++) {
            $existing = $this->store->begin($executionId, $stepPosition, $scoped, $this->clock->now());

            if ($existing === null) {
                $this->assertWithinBound($executionId, $scoped);

                return $this->run($executionId, $scoped, $key, $work);
            }

            switch ($existing->status) {
                case IdempotencyStatus::Settled:
                    return $existing->value;

                case IdempotencyStatus::Failed:
                    // A known failure: the work never took effect, so
                    // the next attempt starts over. Release and begin
                    // again, which re-inserts the marker.
                    $this->store->release($executionId, $scoped);

                    continue 2;

                case IdempotencyStatus::Pending:
                    return $this->uncertain($existing, $executionId, $stepPosition, $step, $scoped, $key, $work, $reconcile);
            }
        }

        // Only reachable if release() and a concurrent begin() keep
        // trading places, which two live attempts on one key can do
        // once; the second pass found it pending and took the branch
        // above, or settled it. Treat a third collision as uncertain.
        throw IdempotentOutcomeUncertainException::forKey($step, $stepPosition, $key, $this->clock->now());
    }

    /**
     * The record was begun and never settled or failed: the outcome is
     * unknown. A reconciler may know; without one, this is raised.
     *
     * @param  Closure(): mixed  $work
     * @param  (Closure(): mixed)|null  $reconcile
     */
    private function uncertain(
        IdempotencyRecord $record,
        int $executionId,
        int $stepPosition,
        string $step,
        string $scoped,
        string $key,
        Closure $work,
        ?Closure $reconcile,
    ): mixed {
        if ($reconcile === null) {
            throw IdempotentOutcomeUncertainException::forKey($step, $stepPosition, $key, $record->startedAt);
        }

        // A throwing reconciler leaves the record pending — the next
        // attempt asks again — and fails the step with its own error.
        $known = $reconcile();

        if ($known !== null) {
            // The provider says it happened; this is the outcome.
            DurableValue::check($known, "remember.{$key}", $this->options->maxPayloadBytes);
            $this->store->settle($executionId, $scoped, $known, $this->clock->now());

            return $known;
        }

        // The reconciler asserts the side effect never landed. That
        // assertion is the application's to make; the work runs from
        // the start, over the pending marker.
        return $this->run($executionId, $scoped, $key, $work);
    }

    /**
     * Run the work under a pending marker and record how it ended.
     *
     * @param  Closure(): mixed  $work
     */
    private function run(int $executionId, string $scoped, string $key, Closure $work): mixed
    {
        try {
            $value = $work();
        } catch (Throwable $exception) {
            // An exception is a known outcome — Laraflow got to run
            // code — unless the exception itself says otherwise. A
            // client timeout after the bytes were sent is the case the
            // marker interface exists for; it leaves the record pending
            // so the next attempt reconciles or raises.
            if (! $exception instanceof IndicatesUncertainOutcome) {
                $this->store->fail($executionId, $scoped, $exception::class, $this->clock->now());
            }

            throw $exception;
        }

        // Checked before the write, so an oversized or unserializable
        // value fails by name rather than as a driver error. The record
        // is left pending on purpose: the side effect *did* happen and
        // the value cannot be kept, so the honest state for the next
        // attempt is "unknown — reconcile", not "never ran".
        DurableValue::check($value, "remember.{$key}", $this->options->maxPayloadBytes);

        $this->store->settle($executionId, $scoped, $value, $this->clock->now());

        return $value;
    }

    /**
     * The cap is checked after the insert rather than before, so a
     * replay of a key that already exists is never refused at the cap,
     * and a concurrent pair can overshoot it by at most one.
     */
    private function assertWithinBound(int $executionId, string $scoped): void
    {
        $max = $this->options->maxIdempotencyKeysPerExecution;

        if ($this->store->count($executionId) > $max) {
            $this->store->release($executionId, $scoped);

            throw TooManyIdempotencyKeysException::forExecution($executionId, $max);
        }
    }

    /**
     * @throws InvalidIdempotencyKeyException
     */
    private function scope(int $executionId, int $stepPosition, string $key): string
    {
        if ($key === '') {
            throw InvalidIdempotencyKeyException::empty();
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw InvalidIdempotencyKeyException::controlCharacters($key);
        }

        $scoped = sprintf('laraflow:%d:%d:%s', $executionId, $stepPosition, $key);

        if (mb_strlen($scoped) > self::MAX_KEY_LENGTH) {
            throw InvalidIdempotencyKeyException::tooLong($key, mb_strlen($scoped), self::MAX_KEY_LENGTH);
        }

        return $scoped;
    }
}
