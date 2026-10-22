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
            $this->artisan('vendor:publish', ['--tag' => 'laraflow-config'])->assertSuccessful();

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
            $this->artisan('vendor:publish', ['--tag' => 'laraflow-migrations'])->assertSuccessful();

            $expected->each(fn (string $path) => $this->assertFileExists($path));
        } finally {
            $expected->each(fn (string $path) => File::delete($path));
        }
    }

    public function test_rolling_back_the_migrations_drops_the_package_tables(): void
    {
        $this->assertTrue(Schema::hasTable('laraflow_step_executions'));

        $this->artisan('migrate:rollback')->assertSuccessful();

        $this->assertFalse(Schema::hasTable('laraflow_step_executions'));
        $this->assertFalse(Schema::hasTable('laraflow_workflow_executions'));
    }
}
