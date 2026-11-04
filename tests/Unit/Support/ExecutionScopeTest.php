<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Support;

use Laraflow\Support\ExecutionScope;
use Laraflow\Support\LoggingContext;
use Laraflow\Workflow\WorkflowExecution;
use PHPUnit\Framework\TestCase;

class ExecutionScopeTest extends TestCase
{
    public function test_nothing_is_current_outside_a_run(): void
    {
        $scope = new ExecutionScope(new LoggingContext(null));

        $this->assertFalse($scope->active());
        $this->assertNull($scope->current());
        $this->assertNull($scope->tenantId());

        // A finally that leaves without having entered is a no-op, not
        // an error: the try may not have reached the enter.
        $scope->leave();

        $this->assertFalse($scope->active());
    }

    public function test_the_entered_execution_is_current_until_left(): void
    {
        $scope = new ExecutionScope(new LoggingContext(null));
        $execution = $this->execution(1, 'acme');

        $scope->enter($execution);

        $this->assertTrue($scope->active());
        $this->assertSame($execution, $scope->current());
        $this->assertSame('acme', $scope->tenantId());

        $scope->leave();

        $this->assertNull($scope->current());
        $this->assertNull($scope->tenantId());
    }

    public function test_entries_nest_and_leaving_restores_the_outer_execution(): void
    {
        $scope = new ExecutionScope(new LoggingContext(null));
        $outer = $this->execution(1, 'acme');
        $inner = $this->execution(2, 'globex');

        $scope->enter($outer);
        $scope->enter($inner);

        $this->assertSame($inner, $scope->current());
        $this->assertSame('globex', $scope->tenantId());

        $scope->leave();

        $this->assertSame($outer, $scope->current(), 'The outer run is current again, not nothing.');
        $this->assertSame('acme', $scope->tenantId());

        $scope->leave();

        $this->assertFalse($scope->active());
    }

    public function test_an_execution_without_a_tenant_has_no_tenant_to_scope_by(): void
    {
        $scope = new ExecutionScope(new LoggingContext(null));

        $scope->enter($this->execution(1, null));

        $this->assertTrue($scope->active());
        $this->assertNull($scope->tenantId());
    }

    protected function execution(int $id, ?string $tenantId): WorkflowExecution
    {
        return new WorkflowExecution(id: $id, workflow: 'App\\Workflows\\Sync', tenantId: $tenantId);
    }
}
