<?php

declare(strict_types=1);

namespace Laraflow\Console\Generators;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Scaffolds a Workflow in app/Workflows with an empty plan, so the
 * first workflow is generated rather than typed.
 */
#[AsCommand(name: 'laraflow:make-workflow', aliases: ['make:workflow'])]
class MakeWorkflowCommand extends GeneratorCommand
{
    use PublishesStubs;
    use WarnsOnDeprecatedName;

    protected $name = 'laraflow:make-workflow';

    /** @var list<string> Deprecated since 0.6.0; see WarnsOnDeprecatedName. */
    protected $aliases = ['make:workflow'];

    protected $description = 'Create a new Laraflow workflow class';

    protected $type = 'Workflow';

    protected function getStub(): string
    {
        return $this->resolveStubPath('workflow.stub');
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Workflows';
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the workflow already exists'],
        ];
    }
}
