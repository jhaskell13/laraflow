<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use DateTimeImmutable;
use Laraflow\Workflow\IdempotencyRecord;
use Laraflow\Workflow\IdempotencyStore;
use RuntimeException;

/**
 * An IdempotencyStore that dies at a named method — before or after
 * delegating — so a test can leave the record in exactly the state a
 * worker crash at that instant leaves it. The counterpart of
 * CrashingExecutionRepository.
 */
class CrashingIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private IdempotencyStore $inner,
        private string $dieOn,
        private string $phase = 'before',
    ) {}

    public function begin(int $executionId, int $stepPosition, string $key, DateTimeImmutable $at): ?IdempotencyRecord
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->begin($executionId, $stepPosition, $key, $at));
    }

    public function settle(int $executionId, int $stepPosition, string $key, mixed $value, DateTimeImmutable $at): void
    {
        $this->call(__FUNCTION__, fn () => $this->inner->settle($executionId, $stepPosition, $key, $value, $at));
    }

    public function fail(int $executionId, int $stepPosition, string $key, string $errorClass, DateTimeImmutable $at): void
    {
        $this->call(__FUNCTION__, fn () => $this->inner->fail($executionId, $stepPosition, $key, $errorClass, $at));
    }

    public function release(int $executionId, int $stepPosition, string $key): bool
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->release($executionId, $stepPosition, $key));
    }

    public function count(int $executionId): int
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->count($executionId));
    }

    public function records(int $executionId): array
    {
        return $this->call(__FUNCTION__, fn () => $this->inner->records($executionId));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $delegate
     * @return T
     */
    private function call(string $method, callable $delegate): mixed
    {
        if ($method === $this->dieOn && $this->phase === 'before') {
            throw new RuntimeException("worker lost before {$method}()");
        }

        $result = $delegate();

        if ($method === $this->dieOn && $this->phase === 'after') {
            throw new RuntimeException("worker lost after {$method}()");
        }

        return $result;
    }
}
