<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\HttpConnector;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

/**
 * Pages through the stub's listing, lazily and under a budget — one
 * connector event per page, and a refusal rather than a short list if
 * the budget is reached.
 */
final class ListPages implements Step
{
    use Works;

    public function __construct(private readonly LaraflowManager $laraflow) {}

    public function handle(WorkflowContext $context): mixed
    {
        $connector = $this->laraflow->connector('soak');

        if (! $connector instanceof HttpConnector) {
            return $this->output($context, ['items' => 0]);
        }

        $items = 0;

        foreach ($connector->items(new ConnectorRequest('GET', '/pages'), maxPages: 6) as $item) {
            $items++;
        }

        return $this->output($context, ['items' => $items]);
    }
}
