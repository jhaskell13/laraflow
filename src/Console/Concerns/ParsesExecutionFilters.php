<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use DateTimeImmutable;
use InvalidArgumentException;
use Laraflow\Support\FilterInput;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;

/**
 * @internal Option parsing shared by the Laraflow console commands that
 * build an ExecutionFilter, so two commands cannot disagree about what
 * `--since` or `--status` means.
 *
 * @mixin \Illuminate\Console\Command
 */
trait ParsesExecutionFilters
{
    /**
     * Build a filter from whichever of the standard options this
     * command actually declares. Guarding on the definition lets one
     * parser serve commands with different option sets — a summary has
     * no --status, because it reports every status.
     *
     * @throws InvalidArgumentException for an unknown status or an unparseable time.
     */
    protected function filterFromOptions(bool $oldestFirst = false): ExecutionFilter
    {
        return new ExecutionFilter(
            workflow: $this->hasOptionDefined('workflow') ? $this->arrayOption('workflow') : null,
            status: $this->hasOptionDefined('status')
                ? array_map($this->toStatus(...), $this->arrayOption('status'))
                : null,
            correlationId: $this->hasOptionDefined('correlation') ? $this->stringOption('correlation') : null,
            tenantId: $this->hasOptionDefined('tenant') ? $this->stringOption('tenant') : null,
            createdAfter: $this->hasOptionDefined('since') ? $this->timeOption('since') : null,
            createdBefore: $this->hasOptionDefined('until') ? $this->timeOption('until') : null,
            oldestFirst: $oldestFirst,
        );
    }

    protected function hasOptionDefined(string $name): bool
    {
        return $this->getDefinition()->hasOption($name);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function toStatus(string $status): ExecutionStatus
    {
        return FilterInput::status($status);
    }

    /**
     * @return list<string>
     */
    protected function arrayOption(string $name): array
    {
        $value = $this->option($name);

        // Tolerates a scalar: Artisan::call() may hand one to a
        // repeatable option, and refusing would make these commands
        // awkward to drive programmatically.
        $values = is_array($value) ? $value : ($value === null ? [] : [$value]);

        return array_values(array_filter(
            array_map(fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $values),
            fn (string $item): bool => $item !== '',
        ));
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @throws InvalidArgumentException when the value is not a time.
     */
    protected function timeOption(string $name): ?DateTimeImmutable
    {
        $value = $this->stringOption($name);

        if ($value === null) {
            return null;
        }

        // The shared parser, so the console and the dashboard read a
        // time the same way; the field is named as an option here.
        return FilterInput::time($value, "--{$name} option");
    }

    protected function statusList(): string
    {
        return FilterInput::statusList();
    }

    /**
     * A bulk operation needs a filter: there is no "cancel everything",
     * because a flag-less command that acts on every execution is a
     * typo away from an incident.
     *
     * @throws InvalidArgumentException
     */
    protected function assertSomeCriterion(ExecutionFilter $filter, string $verb): void
    {
        if ($filter->isEmpty()) {
            throw new InvalidArgumentException(
                "A bulk {$verb} needs at least one filter — --status, --workflow, --correlation, --tenant, --since, or --until — "
                .'or an execution id to act on one. There is no form of this command that acts on everything.'
            );
        }
    }

    /**
     * One execution or a filter, never both: a command given an id and
     * a filter has been told two different things to act on.
     *
     * @throws InvalidArgumentException
     */
    protected function assertNoFilter(): void
    {
        foreach (['status', 'workflow', 'correlation', 'tenant', 'since', 'until', 'limit', 'dry-run'] as $option) {
            if (! $this->hasOptionDefined($option)) {
                continue;
            }

            $value = $this->option($option);
            $given = is_array($value) ? $value !== [] : ($value !== null && $value !== false && $value !== '');

            if ($given) {
                throw new InvalidArgumentException(
                    "Pass either an execution id or a filter, not both: --{$option} selects executions by filter, and an id was given too."
                );
            }
        }
    }
}
