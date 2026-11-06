<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows;

use DateTimeImmutable;
use Laraflow\Workflow\Schedule;
use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\Steps\RecordTick;

/**
 * Runs every minute, told which minute it is for — so the verifier can
 * check that three racing, skewed schedulers started exactly one
 * execution per tick by reading the executions, not only the ticks.
 */
final class EveryMinuteWorkflow extends Workflow
{
    public function steps(): array
    {
        return [RecordTick::class];
    }

    public function schedule(): Schedule
    {
        return Schedule::cron('* * * * *')
            ->input(fn (DateTimeImmutable $tick): array => ['tick' => $tick->format(DATE_ATOM), 'marker' => 'soak-payload-scheduled']);
    }
}
