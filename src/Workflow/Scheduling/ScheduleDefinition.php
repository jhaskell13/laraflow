<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use Laraflow\Workflow\Schedule;
use Laraflow\Workflow\Workflow;

/**
 * @internal A validated declaration, ready to run: the class, the built
 * instance, its schedule, and the timezone resolved from the
 * declaration or the application's `app.timezone`. Produced by
 * ScheduleRegistry::definitionFor(), consumed by the scheduler.
 */
final readonly class ScheduleDefinition
{
    /**
     * @param  class-string<Workflow>  $workflow
     */
    public function __construct(
        public string $workflow,
        public Workflow $instance,
        public Schedule $schedule,
        public string $timezone,
    ) {}
}
