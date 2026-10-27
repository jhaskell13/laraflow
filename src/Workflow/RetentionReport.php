<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * @internal Returned by RetentionService::prune(); use laraflow:prune.
 *
 * What one retention run did, or — under a dry run — would have done.
 *
 * `skipped` counts executions that were selected by a scan but no
 * longer satisfied the guard when the write ran: something claimed or
 * re-executed them in between. They are not failures. The guard
 * existing at all is the point, and seeing the count is how an
 * operator knows it did its job.
 */
readonly class RetentionReport
{
    public function __construct(
        public int $scanned = 0,
        public int $scrubbed = 0,
        public int $attemptExecutions = 0,
        public int $attemptRows = 0,
        public int $deleted = 0,
        public int $deletedFailed = 0,
        public int $skipped = 0,
        public bool $dryRun = false,
    ) {}

    public function with(
        int $scanned = 0,
        int $scrubbed = 0,
        int $attemptExecutions = 0,
        int $attemptRows = 0,
        int $deleted = 0,
        int $deletedFailed = 0,
        int $skipped = 0,
    ): self {
        return new self(
            scanned: $this->scanned + $scanned,
            scrubbed: $this->scrubbed + $scrubbed,
            attemptExecutions: $this->attemptExecutions + $attemptExecutions,
            attemptRows: $this->attemptRows + $attemptRows,
            deleted: $this->deleted + $deleted,
            deletedFailed: $this->deletedFailed + $deletedFailed,
            skipped: $this->skipped + $skipped,
            dryRun: $this->dryRun,
        );
    }

    /**
     * Executions acted on, across every tier. An execution scrubbed and
     * then having its attempts pruned counts in both tiers, because two
     * things happened to it.
     */
    public function touched(): int
    {
        return $this->scrubbed
            + $this->attemptExecutions
            + $this->deleted
            + $this->deletedFailed;
    }

    public function isEmpty(): bool
    {
        return $this->touched() === 0;
    }
}
