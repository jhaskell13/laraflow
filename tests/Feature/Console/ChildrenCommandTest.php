<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Laraflow\Tests\Support\FanOutWorkflow;
use Laraflow\Tests\Support\OneStepWorkflow;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionRepository;
use Laraflow\Workflow\WorkflowRunner;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * laraflow:children: a fan-out's members and a sub-workflow's child as
 * the page laraflow:list prints, through the same parent filter the
 * dashboard reads.
 */
class ChildrenCommandTest extends TestCase
{
    use RunsArtisanCommands;

    public function test_a_fan_out_parents_members_are_listed_oldest_first_on_request(): void
    {
        $parent = $this->app->make(WorkflowRunner::class)->run(new FanOutWorkflow);
        $members = $this->app->make(ExecutionRepository::class)->list(new ExecutionFilter(parentId: $parent->id, oldestFirst: true), 10);

        $this->assertGreaterThan(1, $members->count(), 'The fixture fans out into several members.');

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:children', ['execution' => (string) $parent->id, '--oldest' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);

        foreach ($members->executions as $member) {
            $this->assertStringContainsString("| {$member->id} ", $output);
        }

        $this->assertStringContainsString(sprintf('Showing %d of %d matching execution(s).', $members->count(), $members->count()), $output);
        $this->assertStringNotContainsString("| {$parent->id} ", $output, 'The parent is not its own child.');
    }

    public function test_the_page_is_bounded_and_the_continuation_names_the_parent(): void
    {
        $parent = $this->app->make(WorkflowRunner::class)->run(new FanOutWorkflow);

        ['output' => $output] = $this->callCommand('laraflow:children', ['execution' => (string) $parent->id, '--limit' => '1', '--oldest' => true]);

        $this->assertStringContainsString('Showing 1 of', $output);
        $this->assertStringContainsString("laraflow:children {$parent->id} --cursor=", $output);
        $this->assertStringContainsString('--oldest --limit=1', $output);
    }

    public function test_a_status_filter_narrows_the_children(): void
    {
        $parent = $this->app->make(WorkflowRunner::class)->run(new FanOutWorkflow);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:children', ['execution' => (string) $parent->id, '--status' => ['failed']]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('has no children in that status', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:children', ['execution' => (string) $parent->id, '--status' => ['exploded']]);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('Unknown status [exploded]', $output);
    }

    public function test_an_execution_without_children_says_so_and_an_unknown_one_is_refused(): void
    {
        $plain = $this->app->make(WorkflowRunner::class)->run(new OneStepWorkflow);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:children', ['execution' => (string) $plain->id]);
        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertStringContainsString('has no child executions', $output);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:children', ['execution' => '999']);
        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('was not found', $output);
    }

    public function test_json_carries_the_parent_and_the_page(): void
    {
        $parent = $this->app->make(WorkflowRunner::class)->run(new FanOutWorkflow);

        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:children', ['execution' => (string) $parent->id, '--json' => true]);

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('laraflow:children', $document['command']);
        $this->assertSame($parent->id, $document['parent']);
        $this->assertSame($document['total'], $document['shown']);
        $this->assertNotEmpty($document['executions']);

        foreach ($document['executions'] as $child) {
            $this->assertSame($parent->id, $child['parent_execution_id']);
            $this->assertArrayHasKey('steps', $child);
            $this->assertArrayNotHasKey('input', $child, 'A listing carries no payloads.');
        }
    }
}
