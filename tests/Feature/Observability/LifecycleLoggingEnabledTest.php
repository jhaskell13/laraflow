<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Observability;

use Laraflow\Tests\Support\ArrayLogger;
use Laraflow\Tests\Support\TwoStepWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\WorkflowRunner;
use Psr\Log\LoggerInterface;

/**
 * Boots the provider with lifecycle logging switched on, as a
 * configured application would.
 *
 * Separate from LifecycleLoggingTest because the listener is registered
 * during boot: a test that registers it by hand proves the logger
 * works, not that the configuration reaches it.
 */
class LifecycleLoggingEnabledTest extends TestCase
{
    public function test_the_provider_registers_the_lifecycle_listener_when_configured(): void
    {
        $logger = new ArrayLogger;
        $this->app->instance(LoggerInterface::class, $logger);

        $this->app->make(WorkflowRunner::class)->run(new TwoStepWorkflow);

        $messages = array_column($logger->records, 'message');

        $this->assertContains('Laraflow: WorkflowExecutionStarted', $messages);
        $this->assertContains('Laraflow: WorkflowExecutionCompleted', $messages);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laraflow.logging.lifecycle', true);
    }
}
