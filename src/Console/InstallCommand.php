<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * The one command between `composer require` and a running workflow:
 * publishes the configuration, runs the migrations, and says what to
 * do next. Each part can be declined, and nothing here does anything
 * `vendor:publish` and `migrate` would not.
 */
class InstallCommand extends Command
{
    protected $signature = 'laraflow:install
        {--skip-migrations : Publish the configuration only, and run php artisan migrate yourself}
        {--publish-migrations : Also copy the migrations into database/migrations, to review or customise them first}
        {--force : Overwrite an already-published configuration file}';

    protected $description = 'Install Laraflow: publish its configuration, run its migrations, and show what to do next';

    public function handle(): int
    {
        $this->components->info('Installing Laraflow.');

        $configPath = $this->laravel->configPath('laraflow.php');
        $hadConfig = file_exists($configPath);

        $this->callSilently('vendor:publish', [
            '--tag' => 'laraflow-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->components->task(
            'Configuration '.($hadConfig && ! (bool) $this->option('force') ? 'already published' : 'published').' to config/laraflow.php',
            fn (): bool => file_exists($configPath),
        );

        if ((bool) $this->option('publish-migrations')) {
            $this->callSilently('vendor:publish', ['--tag' => 'laraflow-migrations']);
            $this->components->task('Migrations published to database/migrations', fn (): bool => true);

            // Published and auto-loaded, Laravel runs each migration
            // once — the sets share names, and the application's copy,
            // read last, is the one that runs. That holds only while
            // the names match: a renamed copy runs beside the original
            // and fails on a table that exists. Said here, where the
            // choice was made, and the choice is the application's.
            $this->components->warn(
                'The package also loads its migrations itself. The two sets share names, so Laravel runs each '
                .'migration once, from your published copy. Set LARAFLOW_LOAD_MIGRATIONS=false so that stays true '
                .'if you rename one.'
            );
        }

        if ((bool) $this->option('skip-migrations')) {
            $this->components->warn('Migrations skipped. Run php artisan migrate before starting a workflow.');
        } else {
            $this->components->info('Running the migrations.');
            $this->call('migrate', ['--force' => true]);
        }

        $this->newLine();
        $this->components->info('Laraflow is installed. Next:');
        $this->components->bulletList([
            'php artisan make:workflow FulfilOrder            a workflow in app/Workflows',
            'php artisan make:step ReserveInventory           a step in app/Workflows/Steps (--conditional, --sub-workflow)',
            'php artisan make:connector StripeConnector       an HTTP connector in app/Connectors',
            'php artisan laraflow:start FulfilOrder           run a workflow you wrote, and inspect it with laraflow:show 1',
            'php artisan laraflow:demo                        or run the bundled demo first',
            'vendor/laraflow/laraflow/docs/execution-lifecycle.md   the durability contract, before depending on it',
        ]);

        return self::SUCCESS;
    }
}
