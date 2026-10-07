<?php

namespace Laraflow\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use Laraflow\Workflow\WorkflowContext;

class WorkflowContextTest extends TestCase
{
    public function test_it_can_store_and_retrieve_values(): void
    {
        $context = new WorkflowContext();

        $context->set('name', 'laraflow');

        $this->assertTrue($context->has('name'));
        $this->assertSame(
            'laraflow',
            $context->get('name')
        );
    }

    public function test_it_returns_default_for_missing_values(): void
    {
        $context = new WorkflowContext();

        $this->assertFalse($context->has('missing'));

        $this->assertSame(
            'default',
            $context->get('missing', 'default')
        );
    }
}