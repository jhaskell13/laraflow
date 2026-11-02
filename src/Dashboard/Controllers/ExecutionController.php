<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laraflow\Dashboard\Support\CursorTrail;
use Laraflow\Dashboard\Support\FilterRequest;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Workflow\AttentionScan;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\IdempotencyStore;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowExecution;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @internal The executions pages: the list, and one execution in full.
 *
 * Reads the public ExecutionRepository and IdempotencyStore and nothing
 * else, exactly as laraflow:list and laraflow:show do — the dashboard
 * is the console's surface with a different renderer
 * (docs/dashboard.md), and the same contract test holds both to it.
 */
final class ExecutionController
{
    /**
     * The repository's own ceiling on a page; asking for more is
     * answered with this many, so the configured value is clamped here
     * rather than silently corrected downstream.
     */
    private const MAX_PER_PAGE = ExecutionRepository::MAX_PAGE_SIZE;

    /**
     * How many children of a parent the detail page lists. A fan-out
     * is a Milestone 9 question; today a parent has the children its
     * sub-workflow steps made, which is a handful.
     */
    private const CHILDREN_SHOWN = 50;

    public function __construct(
        private readonly ExecutionRepository $repository,
        private readonly IdempotencyStore $idempotency,
        private readonly ViewFactory $views,
        private readonly Config $config,
        private readonly ClockInterface $clock,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $filters = FilterRequest::from($request);
        $trail = CursorTrail::from($request);

        try {
            $page = $this->repository->list($filters->filter, $this->perPage(), $trail->current);
        } catch (InvalidArgumentException) {
            // A cursor the repository did not issue — an edited or stale
            // URL. The first page of the same set is the honest answer,
            // not an error.
            return new RedirectResponse(route('laraflow.executions', $filters->query()));
        }

        $ids = array_map(fn (WorkflowExecution $execution): int => $execution->id, $page->executions);

        return $this->views->make('laraflow::dashboard.executions.index', [
            'page' => $page,
            'total' => $this->repository->count($filters->filter),
            // One query for every row's step counts, never one per row.
            'progress' => $ids === [] ? [] : $this->repository->stepProgress($ids),
            'filters' => $filters,
            'trail' => $trail,
            // The workflows on this page, for the filter field to
            // suggest: the contract has no method listing them all, on
            // purpose (docs/dashboard.md, "Filtering").
            'workflowsSeen' => array_values(array_unique(array_map(
                fn (WorkflowExecution $execution): string => $execution->workflow,
                $page->executions,
            ))),
            'now' => $this->clock->now(),
        ]);
    }

    public function show(int $id): View
    {
        try {
            $execution = $this->repository->find($id);
        } catch (ExecutionNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        $steps = $this->repository->steps($execution);
        $now = $this->clock->now();

        $names = [];

        foreach ($steps as $step) {
            $names[$step->id] = $step->step;
        }

        // Children through the public filter, the way a listing would
        // find them — served by the parent-id index, so one query
        // whether or not there are any; a parent through the link the
        // child carries.
        $children = $this->repository->list(
            new ExecutionFilter(parentId: $execution->id, oldestFirst: true),
            self::CHILDREN_SHOWN,
        );

        return $this->views->make('laraflow::dashboard.executions.show', [
            'execution' => $execution,
            'steps' => $steps,
            'attempts' => $this->repository->attemptHistory($execution),
            'stepNames' => $names,
            // Keys, statuses, and times — never values, which are the
            // results of side effects and may carry anything.
            'remembered' => $this->idempotency->records($execution->id),
            'children' => $children,
            'overdue' => array_filter($steps, fn ($step): bool => AttentionScan::isOverdue($step, $now)),
            'waiting' => $this->waitingOn($steps),
            'now' => $now,
        ]);
    }

    /**
     * What a waiting execution is parked on, in words: the child, the
     * signal and its deadline, or the resume instant — "indefinitely"
     * said out loud when nothing but a signal can wake it.
     *
     * @param  \Laraflow\Workflow\StepExecution[]  $steps
     * @return array{step: \Laraflow\Workflow\StepExecution, kind: string, childId: int|null}|null
     */
    private function waitingOn(array $steps): ?array
    {
        foreach ($steps as $step) {
            if (! $step->isWaiting()) {
                continue;
            }

            if (Wait::isChildSignal($step->waitSignal)) {
                $childId = Wait::childIdFrom((string) $step->waitSignal);

                return ['step' => $step, 'kind' => $childId === 0 ? 'malformed' : 'child', 'childId' => $childId === 0 ? null : $childId];
            }

            if ($step->waitSignal === null) {
                return ['step' => $step, 'kind' => 'timed', 'childId' => null];
            }

            return ['step' => $step, 'kind' => $step->timeoutAt !== null ? 'signal' : 'signal-indefinite', 'childId' => null];
        }

        return null;
    }

    private function perPage(): int
    {
        $configured = $this->config->get('laraflow.dashboard.per_page', 25);
        $configured = is_numeric($configured) ? (int) $configured : 25;

        return max(1, min(self::MAX_PER_PAGE, $configured));
    }
}
