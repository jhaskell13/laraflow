<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Console\Concerns\ListsExecutions;
use Laraflow\Workflow\ExecutionRepository;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Lists executions through the public ExecutionRepository query API —
 * the same filters and keyset pagination an application gets. The
 * command holds no query knowledge of its own and touches no Eloquent
 * model, so anything it can show, application code can show too.
 */
class ListCommand extends Command
{
    use ListsExecutions;

    protected $signature = 'laraflow:list
        {--status=* : Only show executions with this status; repeatable}
        {--workflow=* : Only show executions created from this workflow class; repeatable}
        {--correlation= : Only show executions with this correlation id}
        {--tenant= : Only show executions for this tenant id}
        {--since= : Only show executions created at or after this time (inclusive)}
        {--until= : Only show executions created before this time (exclusive)}
        {--limit=20 : Maximum number of executions to show}
        {--cursor= : Continue after a previous page, using its printed cursor}
        {--oldest : Order oldest first instead of newest first}
        {--json : Print one JSON document instead of the table}';

    protected $description = 'List workflow executions';

    /**
     * The valid statuses are listed from the enum rather than written
     * out, so help text cannot drift from the cases that actually exist.
     */
    public function __construct()
    {
        $this->signature = str_replace(
            'Only show executions with this status;',
            'Only show executions with this status ('.$this->statusList().');',
            $this->signature,
        );

        parent::__construct();
    }

    public function handle(ExecutionRepository $repository): int
    {
        try {
            $filter = $this->filterFromOptions((bool) $this->option('oldest'));
            $limit = $this->limitOption(20);
            $page = $repository->list($filter, $limit, $this->stringOption('cursor'));
        } catch (InvalidArgumentException $exception) {
            // An unknown status, an unparseable time, a malformed
            // cursor: the repository refuses to guess, and so does the
            // command.
            return $this->refuse($exception->getMessage());
        }

        return $this->renderPage(
            $repository,
            $filter,
            $page,
            continue: 'laraflow:list',
            options: $this->repeatedOptions(),
            empty: $filter->isEmpty() ? 'No executions found. Try: laraflow:demo' : 'No executions match that filter.',
        );
    }
}
