<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;
use PHPUnit\Framework\TestCase;

class ExecutionFilterTest extends TestCase
{
    public function test_an_empty_filter_matches_everything(): void
    {
        $filter = ExecutionFilter::all();

        $this->assertTrue($filter->isEmpty());
        $this->assertNull($filter->workflows);
        $this->assertNull($filter->statuses);
        $this->assertFalse($filter->oldestFirst, 'Newest first is the default.');
    }

    public function test_a_single_workflow_or_status_is_normalised_to_a_list(): void
    {
        $filter = new ExecutionFilter(workflow: 'App\Workflows\Sync', status: ExecutionStatus::Failed);

        $this->assertSame(['App\Workflows\Sync'], $filter->workflows);
        $this->assertSame([ExecutionStatus::Failed], $filter->statuses);
        $this->assertFalse($filter->isEmpty());
    }

    public function test_duplicate_workflows_and_statuses_are_collapsed(): void
    {
        $filter = new ExecutionFilter(
            workflow: ['A', 'B', 'A'],
            status: [ExecutionStatus::Failed, ExecutionStatus::Failed, ExecutionStatus::Retrying],
        );

        $this->assertSame(['A', 'B'], $filter->workflows);
        $this->assertSame([ExecutionStatus::Failed, ExecutionStatus::Retrying], $filter->statuses);
    }

    public function test_an_empty_list_means_unfiltered_not_match_nothing(): void
    {
        $filter = new ExecutionFilter(workflow: [], status: []);

        $this->assertNull($filter->workflows);
        $this->assertNull($filter->statuses);
        $this->assertTrue($filter->isEmpty());
    }

    public function test_blank_identifiers_mean_unfiltered(): void
    {
        // Mirrors ExecutionOptions: a caller forwarding an optional input
        // must not end up with a filter that matches nothing.
        $filter = new ExecutionFilter(correlationId: '  ', tenantId: '');

        $this->assertNull($filter->correlationId);
        $this->assertNull($filter->tenantId);
        $this->assertTrue($filter->isEmpty());
    }

    public function test_identifiers_are_trimmed(): void
    {
        $filter = new ExecutionFilter(correlationId: ' req-1 ', tenantId: "acme\n");

        $this->assertSame('req-1', $filter->correlationId);
        $this->assertSame('acme', $filter->tenantId);
    }

    public function test_a_non_string_workflow_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workflow filter');

        // Deliberately the wrong type: the declared signature is a contract for callers, not a guarantee.
        new ExecutionFilter(workflow: [42]);
    }

    public function test_a_non_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('status filter');

        // Deliberately the wrong type: the declared signature is a contract for callers, not a guarantee.
        new ExecutionFilter(status: ['failed']);
    }

    public function test_an_inverted_creation_window_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('createdAfter');

        new ExecutionFilter(
            createdAfter: new DateTimeImmutable('2026-10-02'),
            createdBefore: new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_an_empty_finish_window_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finishedAfter');

        $instant = new DateTimeImmutable('2026-10-01');

        // Half-open [t, t) is empty; a filter that can match nothing is a bug.
        new ExecutionFilter(finishedAfter: $instant, finishedBefore: $instant);
    }

    public function test_one_sided_windows_are_fine(): void
    {
        $filter = new ExecutionFilter(createdBefore: new DateTimeImmutable('2026-10-01'));

        $this->assertNull($filter->createdAfter);
        $this->assertNotNull($filter->createdBefore);
        $this->assertFalse($filter->isEmpty());
    }
}
