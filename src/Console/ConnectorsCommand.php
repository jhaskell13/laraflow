<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\Connection;
use Laraflow\Integration\CredentialProvider;
use Laraflow\Integration\HttpConnector;
use Throwable;

/**
 * @experimental Console tooling is still evolving; see docs/public-api.md.
 *
 * What connectors this application has, what connections each one
 * serves, and how each is configured — the operational answer to "is
 * the staging account pointed at the right host, and does it have a
 * token?".
 *
 * Credential **names** are displayed, never values: the point of this
 * command is to tell an operator whether a credential is present
 * without putting it on a terminal that may be shared or recorded.
 *
 * `--check` resolves every connector and reports what will not build,
 * exiting non-zero, so it can gate a deploy. It sends no requests: a
 * command that called out to every provider to prove reachability
 * would be a different, slower, and much more surprising tool.
 */
class ConnectorsCommand extends Command
{
    protected $signature = 'laraflow:connectors
        {connector? : Only show this connector}
        {--check : Resolve each connector and report configuration problems, exiting non-zero on any}';

    protected $description = 'List registered connectors, their connections, and how each is configured';

    public function handle(LaraflowManager $laraflow, CredentialProvider $credentials): int
    {
        $only = $this->argument('connector');
        $names = $laraflow->connectors();

        if (is_string($only)) {
            if (! $laraflow->has($only)) {
                $this->components->error("Connector [{$only}] is not registered.");

                return self::FAILURE;
            }

            $names = [$only];
        }

        if ($names === []) {
            $this->components->info('No connectors are registered. Declare one under laraflow.integrations.');

            return self::SUCCESS;
        }

        $rows = [];
        $problems = [];

        foreach ($names as $name) {
            try {
                $connections = $laraflow->connections($name);
            } catch (Throwable $exception) {
                $problems[] = [$name, '-', $exception];

                continue;
            }

            foreach ($connections as $connection) {
                $rows[] = $this->describe($laraflow, $credentials, $name, $connection, $problems);
            }
        }

        if ($rows !== []) {
            $this->table(['Connector', 'Connection', 'Base URL', 'Timeout', 'Retries', 'Rate limit', 'Credentials'], $rows);
        }

        return $this->report($problems);
    }

    /**
     * @param  list<array{string, string, Throwable}>  $problems
     * @return array<int, string>
     */
    protected function describe(
        LaraflowManager $laraflow,
        CredentialProvider $credentials,
        string $name,
        string $connection,
        array &$problems,
    ): array {
        $row = [$name, $connection, '-', '-', '-', '-', '-'];

        try {
            $resolved = $laraflow->connection($name, $connection);
            $row[6] = $this->credentials($credentials, $resolved);
        } catch (Throwable $exception) {
            $problems[] = [$name, $connection, $exception];

            return $row;
        }

        try {
            $connector = $laraflow->connector($name, $connection);
        } catch (Throwable $exception) {
            $problems[] = [$name, $connection, $exception];

            return $row;
        }

        if (! $connector instanceof HttpConnector) {
            $row[2] = '(not HTTP)';

            return $row;
        }

        $options = $connector->options();
        $row[2] = $options->baseUrl ?? '-';
        $row[3] = rtrim(rtrim(number_format($options->timeoutSeconds, 1, '.', ''), '0'), '.').'s';
        $row[4] = $options->retries->enabled() ? $options->retries->maxAttempts.' attempts' : 'off';
        $row[5] = $options->rateLimit === null
            ? 'off'
            : "{$options->rateLimit->maxRequests}/{$options->rateLimit->perSeconds}s";

        return $row;
    }

    /**
     * The names of the credentials this connection resolves — never
     * their values.
     */
    protected function credentials(CredentialProvider $credentials, Connection $connection): string
    {
        $keys = $credentials->credentials($connection)->keys();

        return $keys === [] ? 'none' : implode(', ', $keys);
    }

    /**
     * @param  list<array{string, string, Throwable}>  $problems
     */
    protected function report(array $problems): int
    {
        if ($problems === []) {
            if ($this->option('check')) {
                $this->components->info('Every connector resolved.');
            }

            return self::SUCCESS;
        }

        foreach ($problems as [$name, $connection, $exception]) {
            $this->components->error("[{$name}:{$connection}] ".$exception->getMessage());
        }

        // Without --check these are reported but not fatal: listing what
        // exists should still work when one connection is misconfigured.
        return $this->option('check') ? self::FAILURE : self::SUCCESS;
    }
}
