<?php

declare(strict_types=1);

namespace Laraflow\Testing;

use DateTimeImmutable;
use Laraflow\Workflow\IdempotencyStatus;
use Laraflow\Workflow\IdempotencyStore;
use PHPUnit\Framework\Assert;

/**
 * The assertions an IdempotencyStore must satisfy, published so a
 * custom store can be held to the same contract as the shipped one.
 *
 * The natural Eloquent idiom for begin() — firstOrCreate, return the
 * row — would return the freshly created pending record, and every
 * first call to remember() would then be reported as an uncertain
 * outcome: a silent, total failure of the feature. This test is what
 * catches it. A plain object rather than a base test case or a trait,
 * for the same reasons as ConnectorContract.
 *
 * ```php
 * public function test_it_meets_the_idempotency_store_contract(): void
 * {
 *     $execution = app(WorkflowRunner::class)->start(new AnyWorkflow);
 *
 *     (new IdempotencyStoreContract)->assertHeldBy(app(MyStore::class), $execution->id);
 * }
 * ```
 *
 * The execution must exist: records belong to one.
 */
final class IdempotencyStoreContract
{
    public function assertHeldBy(IdempotencyStore $store, int $executionId, int $stepPosition = 0): void
    {
        $now = new DateTimeImmutable;
        $key = 'contract-'.bin2hex(random_bytes(4));

        Assert::assertNull(
            $store->begin($executionId, $stepPosition, $key, $now),
            'begin() must return null when it creates the marker: null means "yours to run".',
        );

        $found = $store->begin($executionId, $stepPosition, $key, $now);
        Assert::assertNotNull($found, 'begin() must return the existing record when one is already there.');
        Assert::assertSame(IdempotencyStatus::Pending, $found->status, 'A record just begun is pending.');
        Assert::assertSame($key, $found->key, 'The record carries the key as written.');

        Assert::assertFalse($store->release($executionId, $stepPosition, $key), 'release() must not remove a pending record.');

        $store->settle($executionId, $stepPosition, $key, ['charge' => 'CH-1', 'amount' => 12.5], $now);
        $settled = $store->begin($executionId, $stepPosition, $key, $now);
        Assert::assertNotNull($settled);
        Assert::assertSame(IdempotencyStatus::Settled, $settled->status);
        Assert::assertSame(['charge' => 'CH-1', 'amount' => 12.5], $settled->value, 'A settled value round-trips.');

        $store->fail($executionId, $stepPosition, $key, 'RuntimeException', $now);
        $still = $store->begin($executionId, $stepPosition, $key, $now);
        Assert::assertNotNull($still);
        Assert::assertSame(IdempotencyStatus::Settled, $still->status, 'fail() must not touch a record that is no longer pending.');
        Assert::assertFalse($store->release($executionId, $stepPosition, $key), 'release() must not remove a settled record.');

        $other = $key.'-2';
        Assert::assertNull($store->begin($executionId, $stepPosition, $other, $now));
        $store->fail($executionId, $stepPosition, $other, 'RuntimeException', $now);
        $failed = $store->begin($executionId, $stepPosition, $other, $now);
        Assert::assertNotNull($failed);
        Assert::assertSame(IdempotencyStatus::Failed, $failed->status);
        Assert::assertSame('RuntimeException', $failed->errorClass);
        Assert::assertTrue($store->release($executionId, $stepPosition, $other), 'release() removes a failed record and says so.');
        Assert::assertNull($store->begin($executionId, $stepPosition, $other, $now), 'After a release the work may begin again.');

        Assert::assertNull($store->begin($executionId, $stepPosition + 1, $key, $now), 'The same key under another step is another record.');

        Assert::assertSame(3, $store->count($executionId));
        Assert::assertCount(3, $store->records($executionId));
    }
}
