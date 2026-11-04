<?php

declare(strict_types=1);

namespace Laraflow\Console\Generators;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Scaffolds a Step in app/Workflows/Steps: a plain one, a conditional
 * one, or a sub-workflow step. One of each kind of step the runtime
 * knows, so the shape is right before a line is written.
 */
#[AsCommand(name: 'laraflow:make-step')]
final class MakeStepCommand extends GeneratorCommand
{
    use PublishesStubs;

    protected $name = 'laraflow:make-step';

    protected $description = 'Create a new Laraflow workflow step class';

    protected $type = 'Step';

    public function handle(): int|bool|null
    {
        if ((bool) $this->option('conditional') && (bool) $this->option('sub-workflow')) {
            // A sub-workflow step's handle() is final, so a condition on
            // it would be a third stub, not a combination of two; the
            // flags together would otherwise silently pick one.
            $this->components->error('A step is either --conditional or --sub-workflow, not both.');

            return self::FAILURE;
        }

        return parent::handle();
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath(match (true) {
            (bool) $this->option('conditional') => 'step.conditional.stub',
            (bool) $this->option('sub-workflow') => 'step.sub-workflow.stub',
            default => 'step.stub',
        });
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Workflows\Steps';
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['conditional', 'c', InputOption::VALUE_NONE, 'A step that can decide, from the context, that it has nothing to do'],
            ['sub-workflow', 's', InputOption::VALUE_NONE, 'A step that runs another workflow as a child execution'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the step already exists'],
        ];
    }
}
