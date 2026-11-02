<?php

declare(strict_types=1);

namespace Laraflow\Console\Generators;

/**
 * @internal Resolves a stub to the application's published copy when
 * one exists, and to the package's otherwise — the convention Laravel's
 * own generators follow, so `vendor:publish --tag=laraflow-stubs` lets
 * an application reshape what the generators write.
 *
 * @mixin \Illuminate\Console\GeneratorCommand
 */
trait PublishesStubs
{
    protected function resolveStubPath(string $stub): string
    {
        $published = $this->laravel->basePath('stubs/laraflow/'.$stub);

        return file_exists($published) ? $published : dirname(__DIR__, 3).'/stubs/'.$stub;
    }
}
