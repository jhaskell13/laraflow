<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use DateTimeImmutable;
use Laraflow\Workflow\Schedule;
use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\Finish;
use Workbench\App\Soak\Workflows\Steps\Linger;
use Workbench\App\Soak\Workflows\Steps\RecordTick;

/**
 * Every minute, without overlapping, and unfinished for longer than a
 * minute by parking — so most of its ticks are recorded as suppressed
 * rather than started, and none hold a worker.
 */
final class NoOverlapWorkflow extends Workflow
{
    public function steps(): array
    {
        return [RecordTick::class, Linger::class, Finish::class];
    }

    public function schedule(): Schedule
    {
        return Schedule::cron('* * * * *')
            ->withoutOverlapping()
            ->input(fn (DateTimeImmutable $tick): array => ['tick' => $tick->format(DATE_ATOM), 'marker' => 'soak-payload-scheduled']);
    }
}
