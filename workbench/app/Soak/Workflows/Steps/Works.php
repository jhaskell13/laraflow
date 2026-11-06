<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\WorkflowContext;

/**
 * What every soak step shares: a little real work, bounded by the
 * attempt's deadline, and the payload sentinel carried into every
 * output so the leak scan has something to look for.
 */
trait Works
{
    protected function work(WorkflowContext $context, int $maxMs): int
    {
        $ms = random_int(1, max(1, $maxMs));
        $remaining = $context->remainingSeconds();

        if ($remaining !== null) {
            $ms = min($ms, max(0, $remaining * 1000 - 100));
        }

        usleep($ms * 1000);

        return $ms;
    }

    protected function marker(WorkflowContext $context): string
    {
        $marker = $context->get('marker');

        return is_string($marker) ? $marker : 'soak-payload-unmarked';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function output(WorkflowContext $context, array $extra = []): array
    {
        return [
            'step' => class_basename(static::class),
            'attempt' => $context->attempt(),
            'marker' => $this->marker($context),
        ] + $extra;
    }
}
