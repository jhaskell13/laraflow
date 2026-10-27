<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;
use Throwable;

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
        return ExecutionStatus::tryFrom($status) ?? throw new InvalidArgumentException(sprintf(
            'Unknown status [%s]. Valid: %s.',
            $status,
            $this->statusList(),
        ));
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

        try {
            // Carbon accepts absolute times and relative expressions
            // alike, so `--since="-2 hours"` works as an operator expects.
            return Carbon::parse($value)->toImmutable();
        } catch (Throwable) {
            throw new InvalidArgumentException(
                "The --{$name} option must be a date or time, got [{$value}]. "
                .'Try an absolute time (2026-10-24 09:00:00) or a relative one (-2 hours).'
            );
        }
    }

    /**
     * Listed from the enum rather than written out, so help text and
     * error messages cannot drift from the cases that exist.
     */
    protected function statusList(): string
    {
        return implode(', ', array_column(ExecutionStatus::cases(), 'value'));
    }
}
