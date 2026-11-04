<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laraflow\Tests\Support\RunsArtisanCommands;
use Laraflow\Tests\TestCase;
use Symfony\Component\Console\Command\Command as CommandStatus;

class InstallCommandTest extends TestCase
{
    use RunsArtisanCommands;

    protected function tearDown(): void
    {
        File::delete($this->app->configPath('laraflow.php'));

        foreach (File::glob($this->app->databasePath('migrations/*laraflow*.php')) ?: [] as $copy) {
            File::delete($copy);
        }

        parent::tearDown();
    }

    public function test_it_publishes_the_configuration_runs_the_migrations_and_says_what_is_next(): void
    {
        ['status' => $status, 'output' => $output] = $this->callCommand('laraflow:install');

        $this->assertSame(CommandStatus::SUCCESS, $status);
        $this->assertFileExists($this->app->configPath('laraflow.php'));
        $this->assertTrue(Schema::hasTable('laraflow_workflow_executions'));
        $this->assertStringContainsString('laraflow:make-workflow', $output);
        $this->assertStringContainsString('laraflow:make-step', $output);
        $this->assertStringContainsString('laraflow:make-connector', $output);
        $this->assertStringContainsString('laraflow:demo', $output);
        $this->assertStringNotContainsString('LARAFLOW_LOAD_MIGRATIONS', $output, 'No warning when migrations were not published.');
    }

    public function test_skipping_migrations_says_so(): void
    {
        ['output' => $output] = $this->callCommand('laraflow:install', ['--skip-migrations' => true]);

        $this->assertStringContainsString('Migrations skipped', $output);
    }

    public function test_publishing_migrations_runs_each_once_from_the_published_copy_and_says_so(): void
    {
        $package = count(File::files(dirname(__DIR__, 3).'/database/migrations'));

        ['output' => $output] = $this->callCommand('laraflow:install', ['--publish-migrations' => true]);

        $copies = File::glob($this->app->databasePath('migrations/*laraflow*.php')) ?: [];
        $this->assertCount($package, $copies, 'Every migration was copied.');
        $this->assertStringContainsString('LARAFLOW_LOAD_MIGRATIONS=false', $output);
        $this->assertStringContainsString('runs each migration once', $output);

        // What `migrate` will run, resolved the way the command resolves
        // it: the registered package path, then the application's.
        // Published and auto-loaded together, the migrator keys files
        // by name, so each migration appears once — and the copy it
        // resolves to is the application's, read last. That is why the
        // warning is about renaming a copy, not about running twice.
        $migrator = $this->app->make('migrator');
        $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [$this->app->databasePath('migrations')]));
        $laraflow = array_filter($files, fn (string $name): bool => str_contains($name, '_laraflow_'), ARRAY_FILTER_USE_KEY);

        $this->assertCount($package, $laraflow, 'Each migration resolves once, none twice.');

        foreach ($laraflow as $name => $path) {
            $this->assertContains($path, $copies, "{$name} resolves to the published copy.");
        }
    }

    public function test_an_already_published_configuration_is_kept_unless_forced(): void
    {
        $path = $this->app->configPath('laraflow.php');
        File::put($path, "<?php return ['edited' => true];");

        ['output' => $output] = $this->callCommand('laraflow:install', ['--skip-migrations' => true]);
        $this->assertStringContainsString('already published', $output);
        $this->assertStringContainsString('edited', File::get($path));

        $this->callCommand('laraflow:install', ['--skip-migrations' => true, '--force' => true]);
        $this->assertStringNotContainsString("'edited'", File::get($path));
    }
}
