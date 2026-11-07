<?php

declare(strict_types=1);

namespace Workbench\App\Soak;

use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\Workflow;
use Workbench\App\Soak\Workflows\ApprovalWorkflow;
use Workbench\App\Soak\Workflows\ConnectorWorkflow;
use Workbench\App\Soak\Workflows\FanOutWorkflow;
use Workbench\App\Soak\Workflows\LinearWorkflow;
use Workbench\App\Soak\Workflows\ParentWorkflow;
use Workbench\App\Soak\Workflows\RetryingWorkflow;
use Workbench\App\Soak\Workflows\SagaWorkflow;

/**
 * The mix docs/soak.md describes, as the thing the load generator asks
 * for one of at a time: a workflow, an input that decides how it will
 * misbehave, and the identifiers it is created with.
 *
 * Every input carries a payload sentinel; every execution carries a
 * correlation id naming its scenario; a third carry a tenant, and the
 * `flood` tenant is the one held to ceilings.
 */
final class Workload
{
    /** @var array<string, int> */
    public const WEIGHTS = [
        'linear' => 30,
        'retrying' => 14,
        'approval' => 10,
        'parent' => 8,
        'fanout' => 6,
        'saga' => 9,
        'connector' => 13,
        'doomed' => 4,
        'overrun' => 3,
        'flake_out' => 3,
    ];

    /** The workflow whose finished executions retention is allowed to remove during the run. */
    public const RETENTION_TARGET = LinearWorkflow::class;

    public function __construct(private readonly string $runId) {}

    public function pick(): string
    {
        $roll = random_int(1, array_sum(self::WEIGHTS));

        foreach (self::WEIGHTS as $scenario => $weight) {
            if (($roll -= $weight) <= 0) {
                return $scenario;
            }
        }

        return 'linear';
    }

    /**
     * @return array{workflow: Workflow, input: array<string, mixed>, options: ExecutionOptions}
     */
    public function build(string $scenario, int $sequence, ?string $tenant = null): array
    {
        $marker = sprintf('%s%s-%d-%s', Env::PAYLOAD_MARKER, $this->runId, $sequence, bin2hex(random_bytes(3)));
        $tenant ??= match (random_int(1, 6)) {
            1 => 'flood',
            2 => 'steady',
            default => null,
        };

        [$workflow, $input] = match ($scenario) {
            'retrying' => [new RetryingWorkflow, ['fails' => random_int(0, 2), 'slow_ms' => random_int(50, 600)]],
            'overrun' => [new RetryingWorkflow, ['fails' => 0, 'overrun' => true]],
            'flake_out' => [new RetryingWorkflow, ['fails' => 9]],
            'approval' => [new ApprovalWorkflow, ['pause' => random_int(1, 6), 'approval_timeout' => random_int(30, 90)]],
            'parent' => [new ParentWorkflow, ['depth' => random_int(1, 3)]],
            'fanout' => [new FanOutWorkflow, ['members' => random_int(2, 12)]],
            'saga' => [new SagaWorkflow, ['doom' => false]],
            'doomed' => [new SagaWorkflow, ['doom' => true, 'undo_fails' => random_int(0, 1) === 1]],
            'connector' => [new ConnectorWorkflow, ['item' => random_int(1, 999)]],
            default => [new LinearWorkflow, ['notify' => random_int(0, 1) === 1]],
        };

        return [
            'workflow' => $workflow,
            'input' => $input + ['marker' => $marker, 'scenario' => $scenario],
            'options' => new ExecutionOptions(
                correlationId: sprintf('soak-%s-%s-%d', $this->runId, $scenario, $sequence),
                tenantId: $tenant,
            ),
        ];
    }
}
