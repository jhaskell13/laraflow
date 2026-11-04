<?php

declare(strict_types=1);

namespace Laraflow\Console\Generators;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal The generators were `make:workflow`, `make:step`, and
 * `make:connector` until 0.6.0 — names in Laravel's own namespace, and
 * ones other workflow packages ship too, so whichever provider booted
 * last silently won (Milestone 10 audit). They are
 * `laraflow:make-workflow` and friends now; the old names stay as
 * aliases for one minor release and say so each time they are used.
 *
 * @mixin \Illuminate\Console\GeneratorCommand
 */
trait WarnsOnDeprecatedName
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $invoked = $input->getFirstArgument();

        if (is_string($invoked) && $invoked !== $this->getName() && in_array($invoked, $this->getAliases(), true)) {
            $this->components->warn(
                "[{$invoked}] is deprecated and will be removed in a future release; use [{$this->getName()}] instead."
            );
        }

        return parent::execute($input, $output);
    }
}
