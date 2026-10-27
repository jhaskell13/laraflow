<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laraflow\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_publishing_the_config_tag_copies_the_config_file_into_the_application(): void
    {
        $target = $this->app->configPath('laraflow.php');
        File::delete($target);

        try {
            $this->command('vendor:publish', ['--tag' => 'laraflow-config'])->assertSuccessful();

            $this->assertFileExists($target);
            $this->assertFileEquals(__DIR__.'/../../config/laraflow.php', $target);
        } finally {
            File::delete($target);
        }
    }

    public function test_publishing_the_migrations_tag_copies_the_migrations_into_the_application(): void
    {
        $directory = $this->app->databasePath('migrations');
        $expected = collect(File::files(__DIR__.'/../../database/migrations'))
            ->map(fn ($file) => $directory.'/'.$file->getFilename());

        $expected->each(fn (string $path) => File::delete($path));

        try {
            $this->command('vendor:publish', ['--tag' => 'laraflow-migrations'])->assertSuccessful();

            $expected->each(fn (string $path) => $this->assertFileExists($path));
        } finally {
            $expected->each(fn (string $path) => File::delete($path));
        }
    }

    public function test_rolling_back_the_migrations_drops_the_package_tables(): void
    {
        $this->assertTrue(Schema::hasTable('laraflow_step_executions'));

        $this->command('migrate:rollback')->assertSuccessful();

        $this->assertFalse(Schema::hasTable('laraflow_step_executions'));
        $this->assertFalse(Schema::hasTable('laraflow_workflow_executions'));

        // Migrate back up before the test ends. SQLite and PostgreSQL
        // would roll the drop back with the test transaction, but MySQL
        // and MariaDB commit on DDL, and the rest of the suite needs
        // the tables regardless of which engine is running.
        $this->command('migrate')->assertSuccessful();
    }
}
