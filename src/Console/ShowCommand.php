<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Exceptions\Workflow\WorkflowException;
use Laraflow\Workflow\ExecutionRepository;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 */
class ShowCommand extends Command
{
    use DisplaysExecutions;

    protected $signature = 'laraflow:show
        {execution : The id of the execution to inspect}
        {--attempts : Also show the per-attempt history of every step}';

    protected $description = 'Inspect a workflow execution and its steps';

    public function handle(ExecutionRepository $repository): int
    {
        try {
            $execution = $repository->find((string) $this->argument('execution'));
        } catch (WorkflowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $steps = $repository->steps($execution);

        $this->displayExecution($execution, $steps);

        if ($execution->claimToken !== null) {
            $this->components->twoColumnDetail(
                '<fg=gray>Claimed at</>',
                $this->formatDate($execution->claimedAt).' (a worker owns or owned this execution)'
            );
            $this->newLine();
        }

        if ((bool) $this->option('attempts')) {
            $this->displayAttemptHistory($repository, $steps);
        }

        return self::SUCCESS;
    }

    /**
     * The append-only attempt history: why each attempt ended, how long
     * it took, and which failures scheduled retries — the step table
     * alone only ever shows the latest attempt.
     *
     * @param  \Laraflow\Workflow\StepExecution[]  $steps
     */
    protected function displayAttemptHistory(ExecutionRepository $repository, array $steps): void
    {
        $rows = [];

        foreach ($steps as $step) {
            foreach ($repository->attempts($step) as $attempt) {
                $rows[] = [
                    class_basename($step->step),
                    $attempt->attempt,
                    $this->coloredStatus($attempt->status->value),
                    $this->formatDate($attempt->startedAt),
                    $attempt->durationMs() !== null ? $attempt->durationMs().' ms' : '—',
                    $attempt->nextRetryAt !== null ? $this->formatDate($attempt->nextRetryAt) : '',
                    $attempt->error ?? '',
                ];
            }
        }

        if ($rows === []) {
            $this->components->info('No finished attempts recorded yet.');

            return;
        }

        $this->table(
            ['Step', 'Attempt', 'Outcome', 'Started', 'Duration', 'Scheduled retry', 'Error'],
            $rows,
        );
    }
}
