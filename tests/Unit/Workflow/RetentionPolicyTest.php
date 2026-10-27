<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Workflow\RetentionPolicy;
use Laraflow\Workflow\RetentionTier;
use PHPUnit\Framework\TestCase;

class RetentionPolicyTest extends TestCase
{
    public function test_nothing_is_retained_away_by_default(): void
    {
        $policy = new RetentionPolicy;

        // Retention is irreversible; a package that began deleting an
        // application's history on upgrade would be indefensible.
        $this->assertFalse($policy->isEnabled());
        $this->assertNull($policy->scrubAfterDays);
        $this->assertNull($policy->pruneAttemptsAfterDays);
        $this->assertNull($policy->deleteAfterDays);
        $this->assertNull($policy->deleteFailedAfterDays);
    }

    public function test_any_single_bound_enables_it(): void
    {
        $this->assertTrue((new RetentionPolicy(scrubAfterDays: 30))->isEnabled());
        $this->assertTrue((new RetentionPolicy(pruneAttemptsAfterDays: 7))->isEnabled());
        $this->assertTrue((new RetentionPolicy(deleteAfterDays: 90))->isEnabled());
        $this->assertTrue((new RetentionPolicy(deleteFailedAfterDays: 90))->isEnabled());
    }

    public function test_cutoffs_are_measured_back_from_now(): void
    {
        $policy = new RetentionPolicy(
            scrubAfterDays: 30,
            pruneAttemptsAfterDays: 7,
            deleteAfterDays: 90,
            deleteFailedAfterDays: 180,
        );

        $now = new DateTimeImmutable('2026-10-25 12:00:00');

        $this->assertSame(
            '2026-09-25 12:00:00',
            $policy->cutoff(RetentionTier::ScrubPayloads, $now)?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-10-18 12:00:00',
            $policy->cutoff(RetentionTier::PruneAttempts, $now)?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-27 12:00:00',
            $policy->cutoff(RetentionTier::Delete, $now)?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-04-28 12:00:00',
            $policy->cutoff(RetentionTier::Delete, $now, failed: true)?->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_disabled_tier_has_no_cutoff(): void
    {
        $policy = new RetentionPolicy(deleteAfterDays: 90);
        $now = new DateTimeImmutable;

        $this->assertNull($policy->cutoff(RetentionTier::ScrubPayloads, $now));
        $this->assertNull($policy->cutoff(RetentionTier::PruneAttempts, $now));
        $this->assertNotNull($policy->cutoff(RetentionTier::Delete, $now));

        // The failed bound is independent: configuring general deletion
        // must not start deleting recoverable work.
        $this->assertNull($policy->cutoff(RetentionTier::Delete, $now, failed: true));
    }

    public function test_a_bound_below_one_day_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 day');

        // Zero would act on work that finished seconds ago.
        new RetentionPolicy(deleteAfterDays: 0);
    }

    public function test_every_bound_is_validated(): void
    {
        foreach ([
            'scrubAfterDays',
            'pruneAttemptsAfterDays',
            'deleteAfterDays',
            'deleteFailedAfterDays',
        ] as $bound) {
            try {
                new RetentionPolicy(...[$bound => -1]);
                $this->fail("A negative {$bound} must be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('at least 1 day', $exception->getMessage());
            }
        }
    }

    public function test_limit_and_chunk_must_be_positive(): void
    {
        foreach (['limit', 'chunk'] as $knob) {
            try {
                new RetentionPolicy(...['deleteAfterDays' => 1, $knob => 0]);
                $this->fail("A zero {$knob} must be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($knob, $exception->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Configuration mapping
    // ------------------------------------------------------------------

    public function test_it_reads_a_configuration_array(): void
    {
        $policy = RetentionPolicy::fromArray([
            'scrub_after_days' => 30,
            'prune_attempts_after_days' => '7',
            'delete_after_days' => 90,
            'delete_failed_after_days' => 180,
            'limit' => 500,
            'chunk' => 50,
        ]);

        $this->assertSame(30, $policy->scrubAfterDays);
        $this->assertSame(7, $policy->pruneAttemptsAfterDays, 'Numeric strings from .env are cast.');
        $this->assertSame(90, $policy->deleteAfterDays);
        $this->assertSame(180, $policy->deleteFailedAfterDays);
        $this->assertSame(500, $policy->limit);
        $this->assertSame(50, $policy->chunk);
    }

    public function test_blank_and_missing_configuration_values_disable_a_bound(): void
    {
        // A stray `LARAFLOW_RETENTION_DELETE_AFTER_DAYS=` must not cast
        // to 0 and be rejected, nor silently mean "delete everything".
        $policy = RetentionPolicy::fromArray([
            'scrub_after_days' => '',
            'delete_after_days' => null,
        ]);

        $this->assertNull($policy->scrubAfterDays);
        $this->assertNull($policy->deleteAfterDays);
        $this->assertFalse($policy->isEnabled());
        $this->assertSame(1000, $policy->limit, 'Omitted knobs keep their defaults.');
        $this->assertSame(100, $policy->chunk);
    }

    public function test_an_empty_configuration_array_is_valid_and_disabled(): void
    {
        $this->assertFalse(RetentionPolicy::fromArray([])->isEnabled());
    }

    // ------------------------------------------------------------------
    // Warnings
    // ------------------------------------------------------------------

    public function test_a_sensible_policy_warns_about_nothing(): void
    {
        $policy = new RetentionPolicy(
            scrubAfterDays: 30,
            pruneAttemptsAfterDays: 7,
            deleteAfterDays: 90,
            deleteFailedAfterDays: 90,
        );

        $this->assertSame([], $policy->warnings());
    }

    public function test_it_warns_when_deletion_outruns_scrubbing(): void
    {
        $policy = new RetentionPolicy(scrubAfterDays: 90, deleteAfterDays: 30);

        $warnings = implode("\n", $policy->warnings());

        $this->assertStringContainsString('scrubbing never runs', $warnings);
    }

    public function test_it_warns_when_deletion_outruns_attempt_pruning(): void
    {
        $policy = new RetentionPolicy(pruneAttemptsAfterDays: 60, deleteAfterDays: 30);

        $this->assertStringContainsString(
            'deleted before its attempt history',
            implode("\n", $policy->warnings()),
        );
    }

    public function test_it_warns_when_failed_work_is_discarded_sooner_than_completed(): void
    {
        $policy = new RetentionPolicy(deleteAfterDays: 90, deleteFailedAfterDays: 30);

        $this->assertStringContainsString(
            'can still be recovered, are discarded sooner',
            implode("\n", $policy->warnings()),
        );
    }

    public function test_equal_delete_bounds_do_not_warn(): void
    {
        // Treating completed and failed work the same is a deliberate,
        // coherent choice.
        $policy = new RetentionPolicy(deleteAfterDays: 90, deleteFailedAfterDays: 90);

        $this->assertSame([], $policy->warnings());
    }
}
