<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Workflow\CompensatableStep;
use Laraflow\Workflow\Compensation;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

final class Reserve implements CompensatableStep, Step
{
    use Works;

    public function handle(WorkflowContext $context): mixed
    {
        return $this->output($context, ['reservation' => 'RS-'.(int) $context->executionId(), 'worked_ms' => $this->work($context, 30)]);
    }

    public function compensate(Compensation $compensation): void
    {
        $compensation->context()->remember('release', fn (): array => [
            'released' => is_array($compensation->output()) ? ($compensation->output()['reservation'] ?? null) : null,
            'marker' => $this->marker($compensation->context()),
        ]);
    }
}
