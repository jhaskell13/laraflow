<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Laraflow\Queue\WorkflowDispatcher;
use Laraflow\Workflow\ExecutionOptions;
use Laraflow\Workflow\WorkflowRunner;
use Throwable;
use Workbench\App\Console\Commands\Concerns\ReadsInput;
use Workbench\App\Workflows\SimulatedWorkflow;

/**
 * Generates workflow traffic for the playground: dispatches executions
 * over time, with a configurable mix of outcomes, so there is something
 * to watch.
 *
 * Deliberately not part of the package. A command whose entire purpose
 * is to create load has no business being installable into an
 * application, and this one lives in workbench/ where only this
 * repository can run it.
 */
class SimulateCommand extends Command
{
    use ReadsInput;

    /**
     * Each scenario is the input that produces it. `retry` and `hang`
     * only look different from `fail` when a retry budget or a step
     * deadline is configured — the command says so when it matters.
     */
    protected const SCENARIOS = [
        'success' => [],
        'retry' => ['fail_at' => 1, 'fail_mode' => 'once'],
        'fail' => ['fail_at' => 1, 'fail_mode' => 'always'],
        'hang' => ['fail_at' => 1, 'hang' => true],
    ];

    protected $signature = 'playground:simulate
        {--count=20 : How many executions to create}
        {--rate=4 : Executions per second}
        {--work=250 : Milliseconds of work per step}
        {--mix=success:7,retry:2,fail:1 : Outcome weights, e.g. success:7,retry:2,fail:1,hang:1}
        {--tenants=acme,globex : Tenants to spread the executions across}
        {--sync : Run each execution in this process instead of queueing it}';

    protected $description = 'Generate workflow traffic for the playground';

    public function handle(WorkflowDispatcher $dispatcher, WorkflowRunner $runner): int
    {
        try {
            $mix = $this->mix();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));
        $rate = max(1, (int) $this->option('rate'));
        $sync = (bool) $this->option('sync');
        $tenants = $this->tenants();

        $this->warnAboutUnconfiguredScenarios($mix);

        $this->components->info(sprintf(
            'Creating %d execution(s) at ~%d/s (%s). %s',
            $count,
            $rate,
            implode(', ', array_map(fn (string $s, int $w): string => "{$s}×{$w}", array_keys($mix), $mix)),
            $sync ? 'Running inline.' : 'Dispatching to the queue.',
        ));

        $sleepMicros = (int) (1_000_000 / $rate);
        $created = ['dispatched' => 0, 'failed' => 0];

        for ($i = 1; $i <= $count; $i++) {
            $scenario = $this->pick($mix);

            $input = [
                'scenario' => $scenario,
                'work_ms' => max(0, (int) $this->option('work')),
                ...self::SCENARIOS[$scenario],
            ];

            $options = new ExecutionOptions(
                correlationId: sprintf('sim-%s-%03d', $scenario, $i),
                tenantId: $tenants[array_rand($tenants)],
            );

            try {
                if ($sync) {
                    $runner->run(new SimulatedWorkflow, $input, $options);
                } else {
                    $dispatcher->dispatch(new SimulatedWorkflow, $input, options: $options);
                }

                $created['dispatched']++;
            } catch (Throwable $exception) {
                // A `fail` scenario run with --sync throws by design, and
                // a run that stops at the first one is not a simulation.
                $created['failed']++;
            }

            if ($i < $count) {
                usleep($sleepMicros);
            }
        }

        $this->components->info(sprintf(
            '%d created, %d ended in an exception here.%s',
            $created['dispatched'],
            $created['failed'],
            $sync ? '' : ' Run a worker to process them: testbench queue:work',
        ));

        return self::SUCCESS;
    }

    /**
     * @return non-empty-array<string, int>
     *
     * @throws InvalidArgumentException
     */
    protected function mix(): array
    {
        $mix = [];

        foreach (explode(',', (string) $this->stringOption('mix')) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            [$scenario, $weight] = array_pad(explode(':', $part, 2), 2, '1');
            $scenario = trim($scenario);

            if (! array_key_exists($scenario, self::SCENARIOS)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown scenario [%s]. Valid: %s.',
                    $scenario,
                    implode(', ', array_keys(self::SCENARIOS)),
                ));
            }

            if (! is_numeric($weight) || (int) $weight < 1) {
                throw new InvalidArgumentException("Weight for [{$scenario}] must be a positive integer.");
            }

            $mix[$scenario] = (int) $weight;
        }

        if ($mix === []) {
            throw new InvalidArgumentException('The --mix option described no scenarios.');
        }

        return $mix;
    }

    /**
     * @param  non-empty-array<string, int>  $mix
     */
    protected function pick(array $mix): string
    {
        $roll = random_int(1, array_sum($mix));

        foreach ($mix as $scenario => $weight) {
            if (($roll -= $weight) <= 0) {
                return $scenario;
            }
        }

        return array_key_first($mix);
    }

    /**
     * @return list<string>
     */
    protected function tenants(): array
    {
        $tenants = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->stringOption('tenants')),
        )));

        return $tenants === [] ? ['acme'] : $tenants;
    }

    /**
     * A `retry` scenario with retries disabled is just a failure, and a
     * `hang` scenario with no deadline configured is just a slow step.
     * Both are confusing to watch, so say so before the traffic starts
     * rather than leaving the viewer to wonder.
     *
     * @param  array<string, int>  $mix
     */
    protected function warnAboutUnconfiguredScenarios(array $mix): void
    {
        if (isset($mix['retry']) && (int) config('laraflow.retry.max_attempts', 1) < 2) {
            $this->components->warn(
                'The retry scenario needs a retry budget, or it is indistinguishable from a failure. '
                .'Set LARAFLOW_RETRY_MAX_ATTEMPTS=3 LARAFLOW_RETRY_BASE_DELAY=5 LARAFLOW_RETRY_JITTER=0.'
            );
        }

        if (isset($mix['hang']) && config('laraflow.timeout.step') === null) {
            $this->components->warn(
                'The hang scenario needs a step deadline to overrun. Set LARAFLOW_STEP_TIMEOUT=2.'
            );
        }
    }
}
