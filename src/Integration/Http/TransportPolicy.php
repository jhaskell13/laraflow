<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use InvalidArgumentException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

/**
 * Whether, and how quickly, one wire request is repeated inside a single
 * step attempt.
 *
 * This is the *inner* retry loop and it is deliberately small: it holds
 * a worker while it waits, so it is for a dropped connection or a
 * momentary 503, measured in milliseconds. The *outer* loop — a step's
 * StepPolicy — is durable, releases the worker, and is where a long
 * wait belongs. The two are kept from multiplying by three rules: this
 * one ships disabled; it never waits longer than `maxRetryAfterSeconds`
 * for a provider's Retry-After, returning the response instead so the
 * step can fail and retry durably; and a request that is not idempotent
 * is retried only when the provider says it was never processed (429)
 * or the caller marked it safe.
 */
final readonly class TransportPolicy
{
    /**
     * Statuses worth a second try by default: told to slow down, or the
     * provider's side failed in a way that is often momentary.
     *
     * @var list<int>
     */
    public const DEFAULT_STATUSES = [429, 500, 502, 503, 504];

    /**
     * @param  int  $maxAttempts  Wire attempts per request, the first included; 1 disables retries.
     * @param  int  $baseDelayMs  Wait after the first failed attempt.
     * @param  float  $multiplier  Exponential factor; 1.0 gives a fixed delay.
     * @param  int  $maxDelayMs  Hard upper bound on any computed delay.
     * @param  float  $jitter  Fraction of the delay used as a ± window (0.0–1.0), deterministic.
     * @param  list<int>  $statuses  Response statuses that are retried.
     * @param  bool  $retryTransportFailures  Retry when no response was obtained at all.
     * @param  int  $maxRetryAfterSeconds  Longest Retry-After honoured in-process; a longer one returns the response.
     */
    public function __construct(
        public int $maxAttempts = 1,
        public int $baseDelayMs = 200,
        public float $multiplier = 2.0,
        public int $maxDelayMs = 2000,
        public float $jitter = 0.2,
        public array $statuses = self::DEFAULT_STATUSES,
        public bool $retryTransportFailures = true,
        public int $maxRetryAfterSeconds = 5,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('retries.max_attempts must be at least 1 (1 disables transport retries).');
        }

        if ($baseDelayMs < 0 || $maxDelayMs < 0) {
            throw new InvalidArgumentException('Transport retry delays cannot be negative.');
        }

        if ($multiplier < 1.0) {
            throw new InvalidArgumentException('retries.multiplier must be at least 1.0; a shrinking backoff is not a backoff.');
        }

        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new InvalidArgumentException('retries.jitter must be a fraction between 0.0 and 1.0.');
        }

        if ($maxRetryAfterSeconds < 0) {
            throw new InvalidArgumentException('retries.max_retry_after cannot be negative.');
        }

        foreach ($statuses as $status) {
            if ($status < 400 || $status > 599) {
                throw new InvalidArgumentException('retries.statuses must be HTTP error statuses (400–599).');
            }
        }
    }

    /**
     * Build from a `retries` configuration array over a base policy,
     * blank values meaning "keep the base".
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, ?self $base = null): self
    {
        $base ??= new self;

        $value = static fn (string $key): mixed => ($config[$key] ?? '') === '' ? null : $config[$key];

        $statuses = $value('statuses');

        return new self(
            maxAttempts: (int) ($value('max_attempts') ?? $base->maxAttempts),
            baseDelayMs: (int) ($value('base_delay_ms') ?? $base->baseDelayMs),
            multiplier: (float) ($value('multiplier') ?? $base->multiplier),
            maxDelayMs: (int) ($value('max_delay_ms') ?? $base->maxDelayMs),
            jitter: (float) ($value('jitter') ?? $base->jitter),
            statuses: is_array($statuses) ? array_values(array_map('intval', $statuses)) : $base->statuses,
            retryTransportFailures: (bool) ($value('transport_failures') ?? $base->retryTransportFailures),
            maxRetryAfterSeconds: (int) ($value('max_retry_after') ?? $base->maxRetryAfterSeconds),
        );
    }

    public function enabled(): bool
    {
        return $this->maxAttempts > 1;
    }

    /**
     * Whether a response to attempt $attempt (1-based) is worth another.
     *
     * @param  int|null  $retryAfterSeconds  The provider's wait, if it gave one.
     */
    public function shouldRetryResponse(ConnectorResponse $response, ConnectorRequest $request, int $attempt, ?int $retryAfterSeconds): bool
    {
        if ($attempt >= $this->maxAttempts || ! in_array($response->status(), $this->statuses, true)) {
            return false;
        }

        // A 429 was rejected before processing: safe for any method. Any
        // other retried status may have been processed.
        if ($response->status() !== 429 && ! $request->isIdempotent()) {
            return false;
        }

        // A long wait holds the worker for nothing it could not do
        // durably; the step's own retry policy owns waits of that size.
        return $retryAfterSeconds === null || $retryAfterSeconds <= $this->maxRetryAfterSeconds;
    }

    /**
     * Whether a request that got no response at all is worth another
     * attempt. Only when the request is idempotent: the provider may
     * have processed it before the connection was lost.
     */
    public function shouldRetryFailure(ConnectorRequest $request, int $attempt): bool
    {
        return $attempt < $this->maxAttempts && $this->retryTransportFailures && $request->isIdempotent();
    }

    /**
     * Milliseconds to wait after attempt $attempt. The provider's
     * Retry-After wins when present; otherwise base × multiplier^(n−1),
     * capped, with jitter derived from $seed so one request's schedule
     * is reproducible.
     */
    public function delayMs(int $attempt, ?int $retryAfterSeconds, string $seed = ''): int
    {
        if ($retryAfterSeconds !== null) {
            return $retryAfterSeconds * 1000;
        }

        $centre = min((float) $this->maxDelayMs, $this->baseDelayMs * ($this->multiplier ** max(0, $attempt - 1)));

        if ($this->jitter <= 0.0 || $centre <= 0.0) {
            return (int) round($centre);
        }

        $half = $this->jitter * $centre;

        if ($centre + $half > $this->maxDelayMs) {
            $centre = max(0.0, $this->maxDelayMs - $half);
        }

        $fraction = abs(crc32("{$seed}:{$attempt}") % 10000) / 10000;

        return (int) max(0, min((float) $this->maxDelayMs, round($centre + ($fraction * 2.0 - 1.0) * $half)));
    }
}
