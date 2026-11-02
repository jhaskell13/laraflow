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
 * The state machine behind WorkflowContext::remember(): what finding a
 * record means, and what to write when the work ends. Kept in one
 * place so a custom IdempotencyStore supplies storage and nothing
 * else — the store cannot disagree with the contract about what a
 * pending record is.
 *
 * Public because WorkflowContext's constructor type-hints it, and a
 * context built outside the runner may need to supply one; its methods
 * beyond construction are not a contract. The same arrangement as
 * ChildWorkflows.
 *
 * Semantics: docs/idempotency.md.
 */
final class IdempotencyResolver
{
    /** A key fits the column it is stored in. */
    public const MAX_KEY_LENGTH = 255;

    /**
     * How many times one call will re-read a record that concurrent
     * attempts keep moving before it gives up and says so.
     */
    private const MAX_PASSES = 3;

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
        $this->validate($key);

        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $existing = $this->store->begin($executionId, $stepPosition, $key, $this->clock->now());

            if ($existing === null) {
                $this->assertWithinBound($executionId, $stepPosition, $key);

                return $this->run($executionId, $stepPosition, $key, $work);
            }

            if ($existing->status === IdempotencyStatus::Settled) {
                return $existing->value;
            }

            if ($existing->status === IdempotencyStatus::Pending) {
                return $this->uncertain($existing, $executionId, $stepPosition, $step, $key, $work, $reconcile);
            }

            // A known failure: the work never took effect, so the next
            // attempt starts over. Only a failed record is released; if
            // nothing was — a concurrent attempt moved it first — the
            // next pass reads what it left.
            $this->store->release($executionId, $stepPosition, $key);
        }

        throw IdempotentOutcomeUncertainException::contended($step, $stepPosition, $key);
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
            // The provider says it happened; this is the outcome. An
            // answer that cannot be stored leaves the record pending,
            // like a value from the work itself (see run()).
            DurableValue::check($known, "remember.{$key}", $this->options->maxPayloadBytes);
            $this->store->settle($executionId, $stepPosition, $key, $known, $this->clock->now());

            return $known;
        }

        // The reconciler asserts the side effect never landed. That
        // assertion is the application's to make; the work runs from
        // the start, over the pending marker.
        return $this->run($executionId, $stepPosition, $key, $work);
    }

    /**
     * Run the work under a pending marker and record how it ended.
     *
     * @param  Closure(): mixed  $work
     */
    private function run(int $executionId, int $stepPosition, string $key, Closure $work): mixed
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
                $this->store->fail($executionId, $stepPosition, $key, $exception::class, $this->clock->now());
            }

            throw $exception;
        }

        // Checked before the write, so an oversized or unserializable
        // value fails by name rather than as a driver error. The record
        // is left pending on purpose: the side effect *did* happen and
        // the value cannot be kept, so the honest state for the next
        // attempt is "unknown — reconcile", not "never ran".
        DurableValue::check($value, "remember.{$key}", $this->options->maxPayloadBytes);

        $this->store->settle($executionId, $stepPosition, $key, $value, $this->clock->now());

        return $value;
    }

    /**
     * The cap is checked after the insert rather than before, so a
     * replay of a key that already exists is never refused at the cap,
     * and a concurrent pair can overshoot it by at most one.
     */
    private function assertWithinBound(int $executionId, int $stepPosition, string $key): void
    {
        $max = $this->options->maxIdempotencyKeysPerExecution;

        if ($this->store->count($executionId) > $max) {
            // Our own pending marker, released through the one path
            // that may remove one: mark it failed first.
            $this->store->fail($executionId, $stepPosition, $key, TooManyIdempotencyKeysException::class, $this->clock->now());
            $this->store->release($executionId, $stepPosition, $key);

            throw TooManyIdempotencyKeysException::forExecution($executionId, $max);
        }
    }

    /**
     * The key is stored as written and compared as written, so it has
     * to be something every supported database stores and compares the
     * same way: valid UTF-8, no control characters, no surrounding
     * whitespace (which MariaDB's default collation ignores), and
     * within the column.
     *
     * @throws InvalidIdempotencyKeyException
     */
    private function validate(string $key): void
    {
        if ($key === '') {
            throw InvalidIdempotencyKeyException::empty();
        }

        if (! mb_check_encoding($key, 'UTF-8')) {
            throw InvalidIdempotencyKeyException::notUtf8();
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $key) === 1) {
            throw InvalidIdempotencyKeyException::controlCharacters($key);
        }

        if (trim($key) !== $key) {
            throw InvalidIdempotencyKeyException::surroundingWhitespace($key);
        }

        if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidIdempotencyKeyException::tooLong($key, mb_strlen($key), self::MAX_KEY_LENGTH);
        }
    }
}
