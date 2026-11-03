<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Laraflow\Dashboard\Support\CursorTrail;
use Laraflow\Dashboard\Support\FilterRequest;
use Laraflow\Exceptions\Workflow\ExecutionNotFoundException;
use Laraflow\Support\Outcome;
use Laraflow\Workflow\AttentionScan;
use Laraflow\Workflow\ChildProgress;
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
        private readonly Router $router,
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
            'queueIsSync' => Outcome::queueIsSync($this->config),
            'canAct' => $this->router->has('laraflow.actions.start'),
            'oldInput' => $request->hasSession() ? $request->session()->getOldInput() : [],
            'reopenStart' => $request->query('start') !== null,
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
        $byPosition = [];

        foreach ($steps as $step) {
            $names[$step->id] = $step->step;
            $byPosition[$step->position] = $step->step;
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
            'waits' => $waits = $this->waitingOn($steps),
            'offers' => $this->offers($execution, $waits),
            // Keyed by position for the remembered-work table, as
            // $stepNames is keyed by id for the attempts table: both
            // maps are the controller's to build.
            'stepNamesByPosition' => $byPosition,
            'queueIsSync' => Outcome::queueIsSync($this->config),
            'canAct' => $this->router->has('laraflow.actions.execute'),
            'now' => $now,
        ]);
    }

    /**
     * Which actions this execution's state admits, and what the
     * signal forms are for. A view renders them; it does not work
     * them out (Milestone 8.5 audit).
     *
     * @param  list<array{step: \Laraflow\Workflow\StepExecution, kind: string, childId: int|null, progress: ChildProgress|null}>  $waits
     * @return array{execute: bool, cancel: bool, early: bool, signallable: list<array{step: \Laraflow\Workflow\StepExecution, kind: string, childId: int|null, progress: ChildProgress|null}>}
     */
    private function offers(WorkflowExecution $execution, array $waits): array
    {
        $signallable = array_values(array_filter(
            $waits,
            fn (array $wait): bool => in_array($wait['kind'], ['signal', 'signal-indefinite'], true),
        ));

        $timed = array_filter($waits, fn (array $wait): bool => $wait['kind'] === 'timed');

        return [
            'execute' => $execution->isPending() || $execution->isFailed() || $execution->isRetrying() || $execution->isWaiting(),
            'cancel' => ! $execution->isCompleted() && ! $execution->isCancelled(),
            'early' => $execution->isRetrying() || $timed !== [],
            'signallable' => $signallable,
        ];
    }

    /**
     * What a waiting execution is parked on, in words: the child, the
     * signal and its deadline, or the resume instant — "indefinitely"
     * said out loud when nothing but a signal can wake it.
     *
     * Every waiting step, not the first: laraflow:show loops, and an
     * execution parked on two waits would otherwise show both in the
     * console and one in the browser — and offer a signal form for one
     * of them (Milestone 8.5 audit).
     *
     * @param  \Laraflow\Workflow\StepExecution[]  $steps
     * @return list<array{step: \Laraflow\Workflow\StepExecution, kind: string, childId: int|null, progress: ChildProgress|null}>
     */
    private function waitingOn(array $steps): array
    {
        $waits = [];

        foreach ($steps as $step) {
            if (! $step->isWaiting()) {
                continue;
            }

            if (Wait::isJoinSignal($step->waitSignal)) {
                $waits[] = [
                    'step' => $step,
                    'kind' => 'join',
                    'childId' => null,
                    'progress' => $this->repository->childProgress($step->id),
                ];

                continue;
            }

            if (Wait::isChildSignal($step->waitSignal)) {
                // A child wait is never indefinite: the parent checks on
                // the child at every heartbeat, recorded on the execution.
                $childId = Wait::childIdFrom((string) $step->waitSignal);

                $waits[] = ['step' => $step, 'kind' => $childId === 0 ? 'malformed' : 'child', 'childId' => $childId === 0 ? null : $childId, 'progress' => null];

                continue;
            }

            if ($step->waitSignal === null) {
                $waits[] = ['step' => $step, 'kind' => 'timed', 'childId' => null, 'progress' => null];

                continue;
            }

            $waits[] = ['step' => $step, 'kind' => $step->timeoutAt !== null ? 'signal' : 'signal-indefinite', 'childId' => null, 'progress' => null];
        }

        return $waits;
    }

    private function perPage(): int
    {
        $configured = $this->config->get('laraflow.dashboard.per_page', 25);
        $configured = is_numeric($configured) ? (int) $configured : 25;

        return max(1, min(self::MAX_PER_PAGE, $configured));
    }
}
