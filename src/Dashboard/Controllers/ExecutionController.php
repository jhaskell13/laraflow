<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowExecution;
use Psr\Clock\ClockInterface;

/**
 * @internal The executions pages: the list, and one execution in full.
 *
 * Reads the public ExecutionRepository and nothing else, exactly as
 * laraflow:list does — the dashboard is the console's surface with a
 * different renderer (docs/dashboard.md), and the same contract test
 * holds both to it.
 */
final class ExecutionController
{
    /**
     * The repository's own ceiling on a page; asking for more is
     * answered with this many, so the configured value is clamped here
     * rather than silently corrected downstream.
     */
    private const MAX_PER_PAGE = ExecutionRepository::MAX_PAGE_SIZE;

    public function __construct(
        private readonly ExecutionRepository $repository,
        private readonly ViewFactory $views,
        private readonly Config $config,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * The dashboard's front door sends the visitor to the list until
     * the overview exists.
     */
    public function home(): RedirectResponse
    {
        return new RedirectResponse(route('laraflow.executions'));
    }

    public function index(Request $request): View|RedirectResponse
    {
        $filter = ExecutionFilter::all();
        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        try {
            $page = $this->repository->list($filter, $this->perPage(), $cursor);
        } catch (InvalidArgumentException) {
            // A cursor the repository did not issue — an edited or stale
            // URL. The first page is the honest answer, not an error.
            return new RedirectResponse(route('laraflow.executions'));
        }

        $ids = array_map(fn (WorkflowExecution $execution): int => $execution->id, $page->executions);

        return $this->views->make('laraflow::dashboard.executions.index', [
            'page' => $page,
            'total' => $this->repository->count($filter),
            // One query for every row's step counts, never one per row.
            'progress' => $ids === [] ? [] : $this->repository->stepProgress($ids),
            'now' => $this->clock->now(),
        ]);
    }

    private function perPage(): int
    {
        $configured = $this->config->get('laraflow.dashboard.per_page', 25);
        $configured = is_numeric($configured) ? (int) $configured : 25;

        return max(1, min(self::MAX_PER_PAGE, $configured));
    }
}
