<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * The configured numbers and flags WorkflowRunner reads, grouped so
 * they stop being interleaved with its collaborators.
 *
 * Settings and collaborators are different kinds of thing: one comes
 * from `config/laraflow.php` and is validated, the other is wired by
 * the container. Mixing them in one constructor made it eleven
 * arguments long and made "which of these is configuration?" a
 * question you had to answer by reading the service provider.
 */
readonly class RunnerOptions
{
    /**
     * @param  int  $staleAfterSeconds  How long a claim may go unrefreshed
     *                                  before another worker may take the execution over.
     * @param  int  $maxErrorLength  Characters an error message is truncated to
     *                               before Laraflow retains it.
     * @param  bool  $includeErrorMessages  Whether failure events carry the
     *                                      message, or only the exception class.
     * @param  int|null  $maxPayloadBytes  Largest a single durable payload may
     *                                     be; null leaves the only limit the database's.
     */
    public function __construct(
        public int $staleAfterSeconds = 300,
        public int $maxErrorLength = 65535,
        public bool $includeErrorMessages = true,
        public ?int $maxPayloadBytes = null,
    ) {
        if ($maxPayloadBytes !== null && $maxPayloadBytes < 1) {
            throw new InvalidArgumentException(
                'laraflow.execution.max_payload_bytes must be at least 1 byte, or null for no limit. '
                .'A limit of zero would reject every payload, including an empty one.'
            );
        }

        // Only max_payload_bytes is validated here, because only it
        // was before. `stale_after` of zero is a legitimate way to say
        // "every claim is immediately reclaimable" — questionable in
        // production, but deliberate in a test simulating takeover —
        // and tightening the other knobs is a decision to take on its
        // own merits, not to smuggle into a refactor.
    }

    /**
     * Read from `laraflow.*`, treating a blank environment value as
     * unset — a stray `LARAFLOW_MAX_PAYLOAD_BYTES=` in `.env` must not
     * cast to 0 and reject every payload the application has.
     */
    public static function fromConfig(Config $config): self
    {
        $payload = self::setting($config, 'laraflow.execution.max_payload_bytes');

        return new self(
            staleAfterSeconds: (int) (self::setting($config, 'laraflow.execution.stale_after') ?? 300),
            maxErrorLength: (int) (self::setting($config, 'laraflow.execution.max_error_length') ?? 65535),
            includeErrorMessages: (bool) ($config->get('laraflow.events.include_error_messages') ?? true),
            maxPayloadBytes: $payload === null ? null : (int) $payload,
        );
    }

    protected static function setting(Config $config, string $key): mixed
    {
        $value = $config->get($key);

        return $value === '' ? null : $value;
    }
}
