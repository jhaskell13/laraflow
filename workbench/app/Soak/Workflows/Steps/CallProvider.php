<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Workflows\Steps;

use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Workflow\Policy\HasStepPolicy;
use Laraflow\Workflow\Policy\StepPolicy;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

/**
 * One call to the stub provider, which answers with whatever status its
 * weights chose: a success, a 429 with Retry-After the durable retry
 * must honour, a 5xx the transport retries inside the attempt, or a
 * 404 that fails the attempt under the step's own policy.
 */
final class CallProvider implements HasStepPolicy, Step
{
    use Works;

    public function __construct(private readonly LaraflowManager $laraflow) {}

    public function stepPolicy(): StepPolicy
    {
        return new StepPolicy(maxAttempts: 3, baseDelaySeconds: 1, maxDelaySeconds: 5);
    }

    public function handle(WorkflowContext $context): mixed
    {
        $response = $this->laraflow
            ->connector('soak')
            ->request(new ConnectorRequest('GET', '/items/'.(int) $context->get('item', 1)))
            ->throw();

        return $this->output($context, [
            'status' => $response->status(),
            'wire_attempts' => $response->attempts(),
            'item' => $response->json('item'),
        ]);
    }
}
