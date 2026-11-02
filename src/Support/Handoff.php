<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowExecution;
use Throwable;

/**
 * @internal What became of handing an execution to the queue, decided
 * once for every caller that does it — `laraflow:start`, `laraflow:demo`,
 * `laraflow:execute --queue`, and the dashboard's execute, start, and
 * signal actions.
 *
 * The decision is not obvious and was typed out three times before this
 * class existed, with two of the copies disagreeing (Milestone 8.5
 * audit). A sync queue connection runs the job *inside* the dispatch
 * call, so a call that returns may mean "queued" or may mean "already
 * finished", and a call that throws may mean "the broker refused" or
 * "a step failed". The configured driver cannot tell them apart — a
 * broker refusing a connection under a nominally sync connection would
 * be read as a step failure with every step untouched. The record can:
 * nothing reached a step while the execution has not moved.
 *
 * Callers render this; they do not re-derive it.
 */
final readonly class Handoff
{
    /** Pushed, and a worker will run it. */
    public const QUEUED = 'queued';

    /** The connection ran it in this process; the execution has moved on. */
    public const RAN = 'ran';

    /** The runtime refused to queue it; nothing was pushed and nothing ran. */
    public const REFUSED = 'refused';

    /** The push itself failed; the execution is persisted and nothing ran. */
    public const PUSH_FAILED = 'push-failed';

    /** It ran in this process and a step threw. */
    public const STEP_FAILED = 'step-failed';

    private function __construct(
        public string $outcome,
        public WorkflowExecution $execution,
        public ?Throwable $exception,
        public string $level,
        public string $message,
        public bool $ok,
    ) {}

    public static function attempt(
        WorkflowDispatcher $dispatcher,
        ExecutionRepository $repository,
        WorkflowExecution $execution,
    ): self {
        $id = $execution->id;

        try {
            $dispatcher->dispatchExecution($id);
        } catch (WorkflowException $exception) {
            // The runtime's own refusal — not runnable, already
            // claimed, a definition that no longer matches. Its message
            // names the thing and the fix, so it is passed through.
            return new self(
                self::REFUSED,
                $repository->find($id),
                $exception,
                Outcome::ERROR,
                $exception->getMessage()
                    ." Execution [{$id}] is persisted and was not queued; run it with: laraflow:execute {$id}",
                false,
            );
        } catch (Throwable $exception) {
            $after = $repository->find($id);

            if (! Outcome::ranInline($execution, $after)) {
                return new self(
                    self::PUSH_FAILED,
                    $after,
                    $exception,
                    Outcome::ERROR,
                    'The execution could not be queued: '.$exception->getMessage()
                        ." Execution [{$id}] is persisted and pending; run it here with: laraflow:execute {$id}",
                    false,
                );
            }

            return new self(
                self::STEP_FAILED,
                $after,
                $exception,
                Outcome::ERROR,
                'The queue connection ran the job in this process (sync driver) and a step failed: '
                    .$exception->getMessage(),
                false,
            );
        }

        $after = $repository->find($id);

        if (Outcome::ranInline($execution, $after)) {
            // Already done, parked, or retrying — the job deliberately
            // does not throw for the last two. Telling an operator to
            // start a worker for work that has run is how a result, or
            // a failure, goes unseen.
            ['level' => $level, 'message' => $message, 'ok' => $ok] = Outcome::describe($after);

            return new self(
                self::RAN,
                $after,
                null,
                $level,
                'The queue connection ran the job in this process (sync driver). '.$message,
                $ok,
            );
        }

        return new self(
            self::QUEUED,
            $after,
            null,
            Outcome::INFO,
            "Queued as execution {$id}. A worker will pick it up — process it with: php artisan queue:work, "
                ."and inspect it with: laraflow:show {$id}",
            true,
        );
    }

    /**
     * Whether the execution's own record should be shown beside the
     * message: it ran, so there is something to look at. A refusal or
     * a failed push changed nothing.
     */
    public function showsRecord(): bool
    {
        return $this->outcome === self::RAN || $this->outcome === self::STEP_FAILED;
    }
}
