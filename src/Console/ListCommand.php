<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\DisplaysExecutions;
use Laraflow\Workflow\ExecutionStatus;
use Laraflow\Workflow\Models\WorkflowExecutionModel;
use Laraflow\Workflow\StepStatus;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Reads the internal models directly; a stable querying API is planned
 * for roadmap Milestone 4.
 */
class ListCommand extends Command
{
    use DisplaysExecutions;

    protected $signature = 'laraflow:list
        {--status= : Only show executions with this status}
        {--limit=20 : Maximum number of executions to show, newest first}';

    protected $description = 'List workflow executions';

    /**
     * The valid statuses are listed from the enum rather than written
     * out, so help text cannot drift from the cases that actually exist.
     */
    public function __construct()
    {
        $this->signature = str_replace(
            'Only show executions with this status',
            'Only show executions with this status ('.$this->statusList().')',
            $this->signature,
        );

        parent::__construct();
    }

    public function handle(): int
    {
        $status = $this->option('status');

        if (is_string($status) && ExecutionStatus::tryFrom($status) === null) {
            $this->components->error(sprintf(
                'Unknown status [%s]. Valid: %s.',
                $status,
                $this->statusList(),
            ));

            return self::FAILURE;
        }

        $executions = WorkflowExecutionModel::query()
            ->with('steps')
            ->when(is_string($status), fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($executions->isEmpty()) {
            $this->components->info('No executions found. Try: laraflow:demo');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Workflow', 'Status', 'Steps', 'Started', 'Finished', 'Error'],
            $executions->map(fn (WorkflowExecutionModel $execution): array => [
                $execution->id,
                class_basename($execution->workflow),
                $this->coloredStatus($execution->status),
                sprintf(
                    '%d/%d',
                    $execution->steps->where('status', StepStatus::Completed->value)->count(),
                    $execution->steps->count(),
                ),
                $this->formatDate($execution->started_at),
                $this->formatDate($execution->completed_at ?? $execution->failed_at),
                $this->formatValue($execution->error, 40) === '—' ? '' : $this->formatValue($execution->error, 40),
            ])->all(),
        );

        return self::SUCCESS;
    }

    protected function statusList(): string
    {
        return implode(', ', array_column(ExecutionStatus::cases(), 'value'));
    }
}
