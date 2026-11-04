<?php

declare(strict_types=1);

namespace Laraflow\Console\Generators;

use Illuminate\Console\Command;
use LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal The generators were `make:workflow`, `make:step`, and
 * `make:connector` until 0.6.0 — names in Laravel's own namespace, and
 * ones other workflow packages ship too, so whichever provider booted
 * last silently won (Milestone 10 audit). They are
 * `laraflow:make-workflow` and friends now; the old names survive for
 * one minor release as this command, registered once per old name.
 *
 * A separate hidden command rather than a Symfony alias on the real
 * one: an alias is printed beside the command it aliases in `artisan
 * list`, which advertises exactly the name being retired. Hidden, the
 * old name still works, says it is deprecated each time it is used,
 * and is listed nowhere.
 */
final class DeprecatedGeneratorName extends Command
{
    public function __construct(string $deprecated, private readonly string $replacement)
    {
        $this->name = $deprecated;
        $this->description = "Deprecated: use {$replacement}";
        $this->hidden = true;

        parent::__construct();
    }

    /**
     * The replacement's arguments and options are copied in before the
     * input is bound, so `make:step Foo --conditional` is read exactly
     * as `laraflow:make-step Foo --conditional` would read it. Done at
     * run time rather than construction: commands resolve lazily, and
     * the replacement may not be loaded when this one is registered.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $definition = $this->replacementCommand()->getDefinition();

        $this->getDefinition()->addArguments(array_values($definition->getArguments()));
        $this->getDefinition()->addOptions(array_values($definition->getOptions()));

        return parent::run($input, $output);
    }

    public function handle(): int
    {
        $this->components->warn(
            "[{$this->getName()}] is deprecated and will be removed in a future release; use [{$this->replacement}] instead."
        );

        $definition = $this->replacementCommand()->getDefinition();
        $parameters = [];

        foreach (array_keys($definition->getArguments()) as $argument) {
            if ($argument !== 'command' && $this->argument($argument) !== null) {
                $parameters[$argument] = $this->argument($argument);
            }
        }

        // Only the replacement's own options, and only those given:
        // Artisan's global options (--verbose, --env, …) already apply
        // to this process, and forwarding a false flag would set it.
        foreach (array_keys($definition->getOptions()) as $option) {
            $value = $this->option($option);

            if ($value !== null && $value !== false && $value !== []) {
                $parameters["--{$option}"] = $value;
            }
        }

        return $this->call($this->replacement, $parameters);
    }

    private function replacementCommand(): \Symfony\Component\Console\Command\Command
    {
        return $this->getApplication()?->find($this->replacement)
            ?? throw new LogicException("[{$this->getName()}] is not registered with a console application.");
    }
}
