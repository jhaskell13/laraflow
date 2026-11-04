<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

/**
 * @internal What one tick run did: an action per schedule decision, the
 * rows no declaration stands behind, and the lost dispatches it healed.
 */
final readonly class SchedulerRun
{
    /**
     * @param  list<ScheduleAction>  $actions
     * @param  list<string>  $orphans  Workflow classes with a row and no declaration.
     * @param  list<int>  $redispatched  Execution ids whose lost dispatch was re-pushed.
     */
    public function __construct(
        public bool $enabled,
        public bool $dryRun,
        public array $actions,
        public array $orphans,
        public array $redispatched,
    ) {}

    /**
     * Whether a person needs to look: a declaration failed, a start
     * failed, or a schedule is orphaned — the tick run's exit-code rule.
     */
    public function needsAttention(): bool
    {
        if ($this->orphans !== []) {
            return true;
        }

        foreach ($this->actions as $action) {
            if ($action->failed()) {
                return true;
            }

            // A tick that started and whose --sync delivery failed in
            // this process: the failure is persisted on the execution,
            // and the operator who asked for an inline run is owed a
            // non-zero exit, exactly as laraflow:recover --sync owes one.
            if ($action->delivery === 'failed') {
                return true;
            }
        }

        return false;
    }

    public function claims(): int
    {
        return count(array_filter($this->actions, fn (ScheduleAction $action): bool => $action->claimed()));
    }
}
