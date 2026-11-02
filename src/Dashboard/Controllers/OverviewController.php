<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laraflow\Dashboard\Support\FilterRequest;
use Laraflow\Workflow\AttentionScan;
use Laraflow\Workflow\ExecutionRepository;
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
        private readonly ExecutionRepository $repository,
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
        $counts = [];

        foreach (ExecutionStatus::cases() as $status) {
            $counts[$status->value] = $total === 0 ? 0 : $this->repository->count($filter->withStatus($status));
        }

        return $this->views->make('laraflow::dashboard.overview', [
            'total' => $total,
            'counts' => $counts,
            'report' => $total === 0 ? null : $this->attention->scan($filter, $now),
            'filters' => $filters,
            'now' => $now,
        ]);
    }
}
