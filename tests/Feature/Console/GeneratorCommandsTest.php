<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laraflow\Integration\HttpConnector;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\ConditionalStep;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\SubWorkflowStep;
use Laraflow\Workflow\Workflow;
use Laraflow\Workflow\WorkflowRunner;
use Symfony\Component\Console\Command\Command as CommandStatus;

/**
 * The Milestone 8 exit criterion for the generators, mechanically: what
 * `make:workflow` and `make:step` write is a workflow `start()` accepts
 * and `run()` completes, before a line of it is edited.
 *
 * Generated classes are required into the process, so each test uses a
 * name no other test will, and everything written is removed again.
 */
class GeneratorCommandsTest extends TestCase
{
    use RunsArtisanCommands;

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            File::delete($path);
        }

        foreach (['Workflows/Steps', 'Workflows', 'Connectors'] as $directory) {
            $full = $this->app->path($directory);

            if (File::isDirectory($full) && File::files($full) === [] && File::directories($full) === []) {
                File::deleteDirectory($full);
            }
        }

        File::deleteDirectory($this->app->basePath('stubs/laraflow'));

        $stubs = $this->app->basePath('stubs');

        if (File::isDirectory($stubs) && File::files($stubs) === [] && File::directories($stubs) === []) {
            File::deleteDirectory($stubs);
        }

        parent::tearDown();
    }

    public function test_a_generated_workflow_with_a_generated_step_starts_and_runs(): void
    {
        $suffix = $this->suffix();
        $step = "Reserve{$suffix}";
        $workflow = "Fulfil{$suffix}";

        $this->assertSame(CommandStatus::SUCCESS, $this->callCommand('make:step', ['name' => $step])['status']);
        $this->assertSame(CommandStatus::SUCCESS, $this->callCommand('make:workflow', ['name' => $workflow])['status']);

        $stepPath = $this->generated("Workflows/Steps/{$step}.php");
        $workflowPath = $this->generated("Workflows/{$workflow}.php");

        // The one edit a developer makes: name the step in the plan.
        File::put($workflowPath, str_replace(
            "            //\n",
            "            \\App\\Workflows\\Steps\\{$step}::class,\n",
            File::get($workflowPath),
        ));

        require_once $stepPath;
        require_once $workflowPath;

        $stepClass = "App\\Workflows\\Steps\\{$step}";
        $workflowClass = "App\\Workflows\\{$workflow}";

        $this->assertTrue(is_subclass_of($stepClass, Step::class), 'The generated step implements Step.');
        $this->assertTrue(is_subclass_of($workflowClass, Workflow::class), 'The generated workflow extends Workflow.');

        $instance = new $workflowClass;
        $this->assertInstanceOf(Workflow::class, $instance);

        // start() applies the plan validation; run() completes the plan.
        $execution = $this->app->make(WorkflowRunner::class)->run($instance);

        $this->assertTrue($execution->isCompleted(), 'A freshly generated workflow runs to completion.');
    }

    public function test_the_conditional_and_sub_workflow_stubs_have_the_right_shape(): void
    {
        $suffix = $this->suffix();

        $this->callCommand('make:step', ['name' => "Gate{$suffix}", '--conditional' => true]);
        $this->callCommand('make:step', ['name' => "Child{$suffix}", '--sub-workflow' => true]);

        require_once $this->generated("Workflows/Steps/Gate{$suffix}.php");
        require_once $this->generated("Workflows/Steps/Child{$suffix}.php");

        $gate = "App\\Workflows\\Steps\\Gate{$suffix}";
        $child = "App\\Workflows\\Steps\\Child{$suffix}";

        $this->assertTrue(is_subclass_of($gate, Step::class));
        $this->assertTrue(is_subclass_of($gate, ConditionalStep::class), 'The conditional stub implements ConditionalStep.');
        $this->assertTrue(is_subclass_of($child, SubWorkflowStep::class), 'The sub-workflow stub extends SubWorkflowStep.');
    }

    public function test_the_two_step_kinds_cannot_be_combined(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('make:step', [
            'name' => 'Never'.$this->suffix(),
            '--conditional' => true,
            '--sub-workflow' => true,
        ]);

        $this->assertSame(CommandStatus::FAILURE, $status);
        $this->assertStringContainsString('not both', $output);
        $this->assertSame([], File::glob($this->app->path('Workflows/Steps/Never*.php')) ?: [], 'Nothing was written.');
    }

    public function test_a_generated_connector_is_a_connector_and_names_its_configuration(): void
    {
        $suffix = $this->suffix();
        $name = "Acme{$suffix}Connector";

        $this->assertSame(CommandStatus::SUCCESS, $this->callCommand('make:connector', ['name' => $name])['status']);

        $path = $this->generated("Connectors/{$name}.php");
        $source = File::get($path);

        // The integration key and the env prefix derive from the name
        // less its "Connector" suffix, snake-cased, so the snippet is
        // a paste: StripeConnector registers as `stripe`.
        $key = Str::snake("Acme{$suffix}");
        $this->assertStringContainsString("laraflow.integrations.{$key}", $source);
        $this->assertStringContainsString("env('".strtoupper($key)."_TOKEN')", $source);

        require_once $path;

        $class = "App\\Connectors\\{$name}";
        $this->assertTrue(is_subclass_of($class, HttpConnector::class));
    }

    public function test_a_published_stub_is_preferred_over_the_packages(): void
    {
        $suffix = $this->suffix();

        $this->callCommand('vendor:publish', ['--tag' => 'laraflow-stubs']);
        $published = $this->app->basePath('stubs/laraflow/step.stub');
        $this->assertFileExists($published, 'The laraflow-stubs tag publishes the stubs.');

        File::put($published, str_replace('return null;', "return 'from the published stub';", File::get($published)));

        $this->callCommand('make:step', ['name' => "Shaped{$suffix}"]);

        $this->assertStringContainsString(
            'from the published stub',
            File::get($this->generated("Workflows/Steps/Shaped{$suffix}.php")),
        );
    }

    public function test_an_existing_class_is_not_overwritten_without_force(): void
    {
        $suffix = $this->suffix();

        $this->callCommand('make:workflow', ['name' => "Keep{$suffix}"]);
        $path = $this->generated("Workflows/Keep{$suffix}.php");
        File::put($path, '<?php // edited');

        // Laravel's generators report an existing class and exit 0;
        // the file, not the exit code, is what this protects.
        ['output' => $output] = $this->callCommand('make:workflow', ['name' => "Keep{$suffix}"]);
        $this->assertStringContainsString('already exists', $output);
        $this->assertSame('<?php // edited', File::get($path), 'The edited file survived.');

        $this->callCommand('make:workflow', ['name' => "Keep{$suffix}", '--force' => true]);
        $this->assertStringContainsString('extends Workflow', File::get($path));
    }

    private function generated(string $relative): string
    {
        $path = $this->app->path($relative);
        $this->assertFileExists($path, "Expected the generator to write {$relative}.");
        $this->written[] = $path;

        return $path;
    }

    private function suffix(): string
    {
        return ucfirst(bin2hex(random_bytes(4)));
    }
}
