<?php

declare(strict_types=1);

namespace Laraflow\Console\Generators;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * Scaffolds an HttpConnector in app/Connectors, with the configuration
 * block that registers it written into the class docblock so the next
 * step is a paste rather than a search.
 */
#[AsCommand(name: 'laraflow:make-connector', aliases: ['make:connector'])]
class MakeConnectorCommand extends GeneratorCommand
{
    use PublishesStubs;
    use WarnsOnDeprecatedName;

    protected $name = 'laraflow:make-connector';

    /** @var list<string> Deprecated since 0.6.0; see WarnsOnDeprecatedName. */
    protected $aliases = ['make:connector'];

    protected $description = 'Create a new Laraflow HTTP connector class';

    protected $type = 'Connector';

    protected function getStub(): string
    {
        return $this->resolveStubPath('connector.stub');
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Connectors';
    }

    /**
     * The class name less a trailing "Connector", as the integration key
     * (snake) and the environment variable prefix (upper snake):
     * `StripeConnector` registers as `stripe` and reads `STRIPE_TOKEN`.
     *
     * @param  string  $name
     */
    protected function buildClass($name): string
    {
        $basename = class_basename($name);
        $base = Str::endsWith($basename, 'Connector') && $basename !== 'Connector'
            ? Str::beforeLast($basename, 'Connector')
            : $basename;
        $key = Str::snake($base);

        return str_replace(
            ['{{ key }}', '{{ env }}'],
            [$key, Str::upper($key)],
            parent::buildClass($name),
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the connector already exists'],
        ];
    }
}
