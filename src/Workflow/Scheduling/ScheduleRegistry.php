<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Scheduling;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Laraflow\Exceptions\Workflow\InvalidScheduleException;
use Laraflow\Workflow\Workflow;
use Throwable;

/**
 * Where declarations meet activation: the set of workflow classes whose
 * schedules are live in this application. The configuration lists them
 * (`laraflow.schedules.workflows`), and a package may register one of
 * its own from its service provider through register() — both paths end
 * here, and there is no privileged one (docs/schedules.md,
 * "Registration").
 *
 * Public because register() is the contract a package schedules through;
 * its methods beyond that serve the scheduler and are not a contract.
 */
final class ScheduleRegistry
{
    /** @var list<string> */
    protected array $registered = [];

    public function __construct(
        protected Container $container,
        protected Config $config,
    ) {}

    /**
     * Register a scheduled workflow programmatically — the connector
     * registration pattern, for schedules.
     */
    public function register(string $workflow): void
    {
        if (trim($workflow) === '') {
            throw new InvalidArgumentException('register() needs a workflow class name.');
        }

        if (! in_array($workflow, $this->registered, true)) {
            $this->registered[] = $workflow;
        }
    }

    /**
     * Every class whose schedule is live: the configured list plus the
     * registered ones, deduplicated, in declaration order. Read lazily
     * on every call, so configuration changes take effect on the next
     * tick run. Entries are validated as names here and as schedules in
     * definitionFor(), so one broken class cannot hide the list.
     *
     * @return list<string>
     */
    public function classes(): array
    {
        $configured = $this->config->get('laraflow.schedules.workflows', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('laraflow.schedules.workflows must be a list of workflow class names.');
        }

        $classes = [];

        foreach ($configured as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException(
                    'laraflow.schedules.workflows must hold workflow class names; found '.get_debug_type($entry).'.'
                );
            }

            $classes[] = $entry;
        }

        return array_values(array_unique(array_merge($classes, $this->registered)));
    }

    /**
     * @internal Serves the scheduler; public-api.md: its methods beyond register() are not a contract.
     *
     * Validate one listed class into a runnable definition.
     *
     * @throws InvalidScheduleException naming the class and what is wrong with it.
     */
    public function definitionFor(string $workflow): ScheduleDefinition
    {
        if (! class_exists($workflow)) {
            throw InvalidScheduleException::missing($workflow);
        }

        if (! is_subclass_of($workflow, Workflow::class)) {
            throw InvalidScheduleException::notAWorkflow($workflow);
        }

        try {
            $instance = $this->container->make($workflow);
        } catch (Throwable $exception) {
            throw InvalidScheduleException::unresolvable($workflow, $exception);
        }

        if (! $instance instanceof Workflow) {
            // The name checks held; what the container chose to bind to
            // it did not.
            throw InvalidScheduleException::notAWorkflow($workflow);
        }

        try {
            $schedule = $instance->schedule();
        } catch (Throwable $exception) {
            throw InvalidScheduleException::invalid($workflow, $exception);
        }

        if ($schedule === null) {
            throw InvalidScheduleException::notScheduled($workflow);
        }

        return new ScheduleDefinition(
            workflow: $workflow,
            instance: $instance,
            schedule: $schedule,
            timezone: $schedule->timezone ?? $this->defaultTimezone(),
        );
    }

    protected function defaultTimezone(): string
    {
        $timezone = $this->config->get('app.timezone');

        return is_string($timezone) && trim($timezone) !== '' ? $timezone : 'UTC';
    }
}
