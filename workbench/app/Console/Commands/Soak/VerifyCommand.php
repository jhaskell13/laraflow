<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Soak;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Workbench\App\Console\Commands\Concerns\ReadsInput;
use Workbench\App\Soak\Env;
use Workbench\App\Soak\Finding;
use Workbench\App\Soak\Invariants;
use Workbench\App\Soak\Workload;

/**
 * The invariants, on their own: against whatever database the soak
 * connection names and whatever run directory holds the logs. The
 * orchestrator runs the same checks at the end of a run; this exists
 * so a run that was interrupted, or a database someone wants to look
 * at again, can still be judged.
 */
final class VerifyCommand extends Command
{
    use ReadsInput;

    protected $signature = 'soak:verify
        {--run-dir= : The run directory whose logs and probes to read (default: SOAK_RUN_DIR)}
        {--json : Print the findings as JSON}';

    protected $description = 'Soak: check every invariant against the current database and a run\'s logs';

    public function handle(ConnectionInterface $db, Container $container): int
    {
        $runDir = $this->stringOption('run-dir') ?? Env::string(Env::RUN_DIR, Env::defaultRunRoot()) ?? Env::defaultRunRoot();

        $findings = (new Invariants($db, $container, $runDir, Env::string(Env::TOKEN), Workload::RETENTION_TARGET))->check();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(array_map(fn (Finding $f): array => $f->toArray(), $findings), JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Invariant', 'Result', 'Violations', 'Detail'],
                array_map(fn (Finding $f): array => [
                    $f->name,
                    $f->passed() ? '<fg=green>pass</>' : '<fg=red>FAIL</>',
                    $f->violations,
                    implode("\n", array_slice($f->details, 0, 3)),
                ], $findings),
            );
        }

        $failed = array_filter($findings, fn (Finding $f): bool => ! $f->passed());

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
