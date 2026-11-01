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
        $this->assertStringContainsString('make:workflow', $output);
        $this->assertStringContainsString('make:step', $output);
        $this->assertStringContainsString('make:connector', $output);
        $this->assertStringContainsString('laraflow:demo', $output);
        $this->assertStringNotContainsString('LARAFLOW_LOAD_MIGRATIONS', $output, 'No warning when migrations were not published.');
    }

    public function test_skipping_migrations_says_so(): void
    {
        ['output' => $output] = $this->callCommand('laraflow:install', ['--skip-migrations' => true]);

        $this->assertStringContainsString('Migrations skipped', $output);
    }

    public function test_publishing_migrations_warns_about_loading_them_twice(): void
    {
        ['output' => $output] = $this->callCommand('laraflow:install', ['--publish-migrations' => true]);

        $this->assertNotEmpty(File::glob($this->app->databasePath('migrations/*laraflow*.php')), 'The migrations were copied.');
        $this->assertStringContainsString('LARAFLOW_LOAD_MIGRATIONS=false', $output);
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
