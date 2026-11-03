<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Facade;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * @experimental Triggers are new in Milestone 10; see docs/triggers.md.
 *
 * The signing scheme, written down once. The signed string binds a
 * request to its target and its moment —
 *
 *     v1 "\n" timestamp "\n" execution id "\n" signal "\n" sha256(body)
 *
 * — and the signature is HMAC-SHA256 over it, lowercase hex, sent as
 * `v1=<hex>` beside the timestamp in its own header so the verifier
 * reads the bytes the signer signed. The path is deliberately absent
 * (a proxy prefix must not break signers); the execution id and signal
 * are deliberately present (a signature for one approval must not open
 * another). The endpoint verifies with this class, and `sign()` signs
 * with it, so the two cannot drift.
 */
final class TriggerSignature
{
    public const VERSION = 'v1';

    public const TIMESTAMP_HEADER = 'X-Laraflow-Timestamp';

    public const SIGNATURE_HEADER = 'X-Laraflow-Signature';

    /** The least a key may be: 256 bits, the HMAC's own output size. */
    public const MIN_KEY_BYTES = 32;

    /**
     * The headers that sign a request to the signed endpoint, under the
     * current key and Laraflow's clock. For a PHP signer — the
     * application's own page, relay, or job.
     *
     * @return array{X-Laraflow-Timestamp: string, X-Laraflow-Signature: string}
     */
    public static function sign(int $executionId, string $signal, string $body = '', ?int $timestamp = null): array
    {
        $app = self::app();

        $keys = self::keys($app->make(Config::class));
        $timestamp ??= $app->make(ClockInterface::class)->now()->getTimestamp();

        return [
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => self::VERSION.'='.self::compute($keys[0], $timestamp, $executionId, $signal, $body),
        ];
    }

    /**
     * The signature for one request under one key: lowercase hex.
     */
    public static function compute(string $key, int $timestamp, int $executionId, string $signal, string $body): string
    {
        return hash_hmac('sha256', self::signedString($timestamp, $executionId, $signal, $body), $key);
    }

    /**
     * What is signed. Public so a signer in another language can be
     * checked against the same bytes.
     */
    public static function signedString(int $timestamp, int $executionId, string $signal, string $body): string
    {
        return implode("\n", [
            self::VERSION,
            (string) $timestamp,
            (string) $executionId,
            $signal,
            hash('sha256', $body),
        ]);
    }

    /**
     * The hex a `v1=<hex>` header presents, or null when the header is
     * not that shape — a refusal, not an error.
     */
    public static function parse(string $header): ?string
    {
        if (preg_match('/^'.self::VERSION.'=([0-9a-f]{64})$/', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Which configured key, if any, produced $presented for this
     * request: 0 for the current key, 1 and up for a previous key still
     * accepted during rotation, null for none. Every comparison is
     * constant time, and every key is tried so the answer's timing does
     * not say which one matched.
     *
     * @param  list<string>  $keys
     */
    public static function matches(string $presented, array $keys, int $timestamp, int $executionId, string $signal, string $body): ?int
    {
        $matched = null;

        foreach ($keys as $index => $key) {
            if (hash_equals(self::compute($key, $timestamp, $executionId, $signal, $body), $presented) && $matched === null) {
                $matched = $index;
            }
        }

        return $matched;
    }

    /**
     * The keys the endpoint verifies against — current first, then any
     * previous keys still accepted — validated loudly: an endpoint that
     * could verify nothing would refuse everything and look like a bug.
     *
     * @return list<string>
     *
     * @throws LogicException naming the setting that is wrong.
     */
    public static function keys(Config $config): array
    {
        $key = $config->get('laraflow.triggers.key');

        if (! is_string($key) || trim($key) === '') {
            throw new LogicException(
                'laraflow.triggers.key is not set. A trigger endpoint verifies every request against it, and an '
                .'endpoint that could verify nothing would refuse everything and look like a bug. Set '
                .'LARAFLOW_TRIGGERS_KEY to at least '.self::MIN_KEY_BYTES.' bytes, or do not call Triggers::routes().'
            );
        }

        if (strlen($key) < self::MIN_KEY_BYTES) {
            throw new LogicException(
                'laraflow.triggers.key must be at least '.self::MIN_KEY_BYTES.' bytes; `openssl rand -hex 32` makes one.'
            );
        }

        $previous = $config->get('laraflow.triggers.previous_keys', []);

        if (! is_array($previous)) {
            throw new LogicException('laraflow.triggers.previous_keys must be a list of keys still accepted during rotation.');
        }

        $keys = [$key];

        foreach ($previous as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                throw new LogicException('laraflow.triggers.previous_keys must hold keys; found '.get_debug_type($candidate).'.');
            }

            $keys[] = $candidate;
        }

        return $keys;
    }

    private static function app(): Container
    {
        $app = Facade::getFacadeApplication();

        if (! $app instanceof Container) {
            throw new LogicException('TriggerSignature::sign() reads the key and the clock from a Laravel application.');
        }

        return $app;
    }
}
