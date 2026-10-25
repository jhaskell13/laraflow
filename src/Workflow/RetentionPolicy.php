<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How long finished executions are kept, and in what form.
 *
 * Every bound is **null by default**: Laraflow deletes nothing until an
 * application says what it wants kept. Retention is irreversible, and a
 * package that quietly started deleting an application's execution
 * history on upgrade would be indefensible.
 *
 * Bounds are measured from `finished_at` — the moment the execution
 * stopped — not from creation, so a long-running execution is not
 * pruned for having started a long time ago.
 */
readonly class RetentionPolicy
{
    /**
     * @param  int|null  $scrubAfterDays  Clear payloads of `completed` and
     *                                    `cancelled` executions finished at least this many days ago.
     *                                    Never applies to `failed` executions, which need their input and
     *                                    context to be re-executable.
     * @param  int|null  $pruneAttemptsAfterDays  Delete attempt history for any
     *                                            finished execution this old. Costs older diagnostics only.
     * @param  int|null  $deleteAfterDays  Delete `completed` and `cancelled`
     *                                     executions this old, with everything belonging to them.
     * @param  int|null  $deleteFailedAfterDays  Delete `failed` executions this
     *                                           old. Separate and explicit, because a failed execution is
     *                                           re-executable: deleting one discards work that could still be
     *                                           recovered, and that must be a decision, not a side effect of
     *                                           configuring general retention.
     * @param  int  $limit  Maximum executions handled per tier per run.
     * @param  int  $chunk  Executions per bulk write.
     */
    public function __construct(
        public ?int $scrubAfterDays = null,
        public ?int $pruneAttemptsAfterDays = null,
        public ?int $deleteAfterDays = null,
        public ?int $deleteFailedAfterDays = null,
        public int $limit = 1000,
        public int $chunk = 100,
    ) {
        foreach ([
            'scrub_after_days' => $scrubAfterDays,
            'prune_attempts_after_days' => $pruneAttemptsAfterDays,
            'delete_after_days' => $deleteAfterDays,
            'delete_failed_after_days' => $deleteFailedAfterDays,
        ] as $name => $days) {
            if ($days !== null && $days < 1) {
                throw new InvalidArgumentException(
                    "laraflow.retention.{$name} must be at least 1 day, or null to disable it. "
                    .'A bound of zero would act on work that finished seconds ago.'
                );
            }
        }

        if ($limit < 1) {
            throw new InvalidArgumentException('laraflow.retention.limit must be at least 1.');
        }

        if ($chunk < 1) {
            throw new InvalidArgumentException('laraflow.retention.chunk must be at least 1.');
        }
    }

    /**
     * Build from the `laraflow.retention` configuration array, treating
     * a blank environment value as unset — a stray
     * `LARAFLOW_RETENTION_DELETE_AFTER_DAYS=` in `.env` must not cast
     * to 0 and be rejected, nor silently mean "delete everything".
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            scrubAfterDays: self::days($config, 'scrub_after_days'),
            pruneAttemptsAfterDays: self::days($config, 'prune_attempts_after_days'),
            deleteAfterDays: self::days($config, 'delete_after_days'),
            deleteFailedAfterDays: self::days($config, 'delete_failed_after_days'),
            limit: self::positive($config, 'limit', 1000),
            chunk: self::positive($config, 'chunk', 100),
        );
    }

    /**
     * Whether any bound is set. A run with none configured does nothing
     * at all, and the command says so rather than reporting a
     * successful no-op an operator might read as "retention is working".
     */
    public function isEnabled(): bool
    {
        return $this->scrubAfterDays !== null
            || $this->pruneAttemptsAfterDays !== null
            || $this->deleteAfterDays !== null
            || $this->deleteFailedAfterDays !== null;
    }

    /**
     * The instant before which work is eligible for $tier, or null when
     * the tier is disabled.
     *
     * @param  bool  $failed  Select the `failed`-specific bound, which
     *                        only the Delete tier has.
     */
    public function cutoff(RetentionTier $tier, DateTimeImmutable $now, bool $failed = false): ?DateTimeImmutable
    {
        $days = match ($tier) {
            RetentionTier::ScrubPayloads => $this->scrubAfterDays,
            RetentionTier::PruneAttempts => $this->pruneAttemptsAfterDays,
            RetentionTier::Delete => $failed ? $this->deleteFailedAfterDays : $this->deleteAfterDays,
        };

        return $days === null ? null : $now->modify("-{$days} days");
    }

    /**
     * Configuration that is valid but probably not what was meant.
     *
     * Reported rather than rejected: each combination below is
     * internally consistent, and an operator may have a reason. They
     * are just far more often a typo.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->deleteAfterDays !== null
            && $this->scrubAfterDays !== null
            && $this->deleteAfterDays <= $this->scrubAfterDays) {
            $warnings[] = sprintf(
                'delete_after_days (%d) is not longer than scrub_after_days (%d), so completed work is '
                .'deleted at or before the point it would have been scrubbed and scrubbing never runs.',
                $this->deleteAfterDays,
                $this->scrubAfterDays,
            );
        }

        if ($this->deleteAfterDays !== null
            && $this->pruneAttemptsAfterDays !== null
            && $this->deleteAfterDays <= $this->pruneAttemptsAfterDays) {
            $warnings[] = sprintf(
                'delete_after_days (%d) is not longer than prune_attempts_after_days (%d), so completed '
                .'work is deleted before its attempt history would be pruned.',
                $this->deleteAfterDays,
                $this->pruneAttemptsAfterDays,
            );
        }

        if ($this->deleteFailedAfterDays !== null
            && $this->deleteAfterDays !== null
            && $this->deleteFailedAfterDays < $this->deleteAfterDays) {
            $warnings[] = sprintf(
                'delete_failed_after_days (%d) is shorter than delete_after_days (%d): failed executions, '
                .'which can still be recovered, are discarded sooner than completed ones.',
                $this->deleteFailedAfterDays,
                $this->deleteAfterDays,
            );
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected static function days(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected static function positive(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return $value === null || $value === '' ? $default : (int) $value;
    }
}
