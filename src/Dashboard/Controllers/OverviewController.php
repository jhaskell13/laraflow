<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laraflow\Dashboard\Support\FilterRequest;
use Laraflow\Workflow\AttentionScan;
use Laraflow\Workflow\ExecutionReader;
use Laraflow\Workflow\ExecutionStatus;
use Psr\Clock\ClockInterface;

/**
 * @internal The front door: what laraflow:summary prints. Counts by
 * status for the scope, and what needs attention — computed by the
 * same AttentionScan the command runs, so the two cannot disagree.
 */
final class OverviewController
{
    public function __construct(
        private readonly ExecutionReader $repository,
        private readonly AttentionScan $attention,
        private readonly ViewFactory $views,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(Request $request): View
    {
        $filters = FilterRequest::from($request);
        $filter = $filters->filter;
        $now = $this->clock->now();

        $total = $this->repository->count($filter);

        // One count() per status, each an indexed query, as the summary
        // does. Statuses come from the enum, so a status added later
        // appears here without this page being touched.
        //
        // Each card is the filter *intersected* with its status, never
        // the filter with its status replaced: withStatus() swaps the
        // status set out, so a `?status=failed` overview used to count
        // every completed execution under "completed" too, and the
        // shares summed past 100%. A status the filter excludes is in
        // no row of the scope, so its card says 0 without asking the
        // database — the card stays, so the grid does not reflow as
        // the filter changes, and 0 is the true count of that status
        // within this scope.
        $counts = [];
        $scoped = $filter->statuses;

        foreach (ExecutionStatus::cases() as $status) {
            $excluded = $scoped !== null && ! in_array($status, $scoped, true);

            $counts[$status->value] = $total === 0 || $excluded
                ? 0
                : $this->repository->count($filter->withStatus($status));
        }

        return $this->views->make('laraflow::dashboard.overview', [
            'total' => $total,
            'counts' => $counts,
            // Scanned even over an empty table: a stalled schedule with
            // no executions yet is exactly the state a dead scheduler
            // leaves, and the one this page must not sleep through —
            // the laraflow:summary rule, held here too.
            'report' => $this->attention->scan($filter, $now),
            'filters' => $filters,
            'now' => $now,
        ]);
    }
}
