<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @internal What a finished run amounts to, in one sentence, and how
 * loudly to say it — the console's report and the dashboard's flash
 * both. Three of the four outcomes are neither failures nor
 * completions: the step's retry policy, a wait, or an operator owns
 * what happens next, so the sentence says who. One definition, so a
 * parked execution cannot be "done" in one place and "waiting" in the
 * other — which is exactly what the Phase C audit found when
 * laraflow:execute kept its own copy.
 */
final class Outcome
{
    public const INFO = 'info';

    public const WARN = 'warn';

    public const ERROR = 'error';

    /**
     * @return array{level: self::INFO|self::WARN|self::ERROR, message: string, ok: bool}
     */
    public static function describe(WorkflowExecution $execution): array
    {
        if ($execution->isRetrying()) {
            return [
                'level' => self::WARN,
                'ok' => true,
                'message' => sprintf(
                    'A step failed and its retry policy scheduled another attempt for %s. '
                    .'Deliver it with: laraflow:recover --sync — or skip the wait with: laraflow:execute %s --now',
                    Formats::date($execution->nextRetryAt),
                    $execution->id,
                ),
            ];
        }

        if ($execution->isWaiting()) {
            return [
                'level' => self::WARN,
                'ok' => true,
                'message' => sprintf(
                    'A step parked the execution on a wait. See what it is waiting for with: laraflow:show %s '
                    .'— a timed wait is delivered by laraflow:recover, a signal by WorkflowRunner::signal().',
                    $execution->id,
                ),
            ];
        }

        if ($execution->isCancelled()) {
            return [
                'level' => self::INFO,
                'ok' => true,
                'message' => 'The execution was cancelled during the run; completed steps are kept.',
            ];
        }

        if (! $execution->isCompleted()) {
            // Every path that reaches here today has already thrown for
            // a terminal failure — FailurePolicy::Continue rethrows the
            // first failure once the remaining steps have run — so this
            // is the guard that keeps the sentence below true rather
            // than a branch with a known caller. A state this method
            // has not been taught is reported as itself, not as done.
            return [
                'level' => self::ERROR,
                'ok' => false,
                'message' => sprintf(
                    'The execution finished in status [%s]. See it with: laraflow:show %s',
                    $execution->status->value,
                    $execution->id,
                ),
            ];
        }

        return [
            'level' => self::INFO,
            'ok' => true,
            'message' => "Execution completed. Inspect it anytime with: laraflow:show {$execution->id}",
        ];
    }

    /**
     * Whether a dispatch call ran the job in this process — the sync
     * queue connection does — judged from the record rather than from
     * configuration, which cannot answer it: a broker refusing a
     * connection under a nominally sync connection would otherwise be
     * reported as a step failure with every step still pending. A run
     * that completed changed the status; one that failed again moved
     * finished_at.
     */
    public static function ranInline(WorkflowExecution $before, WorkflowExecution $after): bool
    {
        return $after->status !== $before->status || $after->finishedAt != $before->finishedAt;
    }

    /**
     * Whether Laraflow's queue connection (or the application default)
     * is the sync driver, which runs a job inside the dispatch call.
     *
     * The other half of ranInline()'s question, and the weaker half:
     * this says what was configured, that says what happened. Use it
     * to decide what to offer an operator — a queue checkbox is
     * pointless without a queue — never to decide what became of a
     * dispatch that has already returned.
     */
    public static function queueIsSync(Config $config): bool
    {
        $connection = $config->get('laraflow.queue.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : (string) $config->get('queue.default');

        return $config->get("queue.connections.{$connection}.driver") === 'sync';
    }
}
