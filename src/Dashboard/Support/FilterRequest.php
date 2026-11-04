<?php

declare(strict_types=1);

namespace Laraflow\Dashboard\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Laraflow\Support\FilterInput;
use Laraflow\Workflow\ExecutionFilter;
use Laraflow\Workflow\ExecutionStatus;

/**
 * @internal The query string as an ExecutionFilter, with the same
 * meanings `laraflow:list` gives its options and the same tolerance:
 * a field that cannot be read is reported and dropped rather than
 * turned into an error page, because an operator who mistyped a date
 * still wants the other filters to hold.
 *
 * Reads exactly the criteria ExecutionFilter offers — the ones the
 * schema serves from an index (docs/dashboard.md, "Filtering").
 */
final readonly class FilterRequest
{
    /** The query parameters this reads, in the order the form shows them. */
    public const FIELDS = ['status', 'workflow', 'correlation', 'tenant', 'since', 'until'];

    /**
     * How many values one repeatable field may carry.
     *
     * A filter is typed by a person, and the form offers seven statuses
     * and one workflow at a time. A crafted URL, though, can carry as
     * many as it likes — and each workflow becomes two more scans on
     * the overview (AttentionScan runs per workflow) and a wider IN
     * clause everywhere, so one shareable link could pin a connection
     * for minutes (Milestone 8.5 audit). Past this the extras are
     * dropped and said so, rather than the page refusing: the filter a
     * person meant is in the first few. The bound itself is shared with
     * the console (FilterInput::MAX_VALUES), which refuses instead.
     */
    private const MAX_VALUES = FilterInput::MAX_VALUES;

    /**
     * @param  list<string>  $problems  What could not be read, in words.
     * @param  array<string, string|list<string>>  $values  What was read, as typed, for the form to show back.
     */
    private function __construct(
        public ExecutionFilter $filter,
        public array $problems,
        public array $values,
    ) {}

    public static function from(Request $request): self
    {
        $problems = [];
        $values = [];

        $statuses = [];

        foreach (self::list($request, 'status', $problems) as $status) {
            try {
                $statuses[] = FilterInput::status($status);
            } catch (InvalidArgumentException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        if ($statuses !== []) {
            $values['status'] = array_map(fn (ExecutionStatus $status): string => $status->value, $statuses);
        }

        // Normalised as the console does: a leading backslash, as PHP
        // writes a fully qualified name, would otherwise match nothing.
        $workflows = array_values(array_unique(array_map(FilterInput::workflow(...), self::list($request, 'workflow', $problems))));

        if ($workflows !== []) {
            $values['workflow'] = $workflows;
        }

        $correlation = self::string($request, 'correlation');
        $tenant = self::string($request, 'tenant');

        if ($correlation !== null) {
            $values['correlation'] = $correlation;
        }

        if ($tenant !== null) {
            $values['tenant'] = $tenant;
        }

        $since = $until = null;

        foreach (['since' => &$since, 'until' => &$until] as $name => &$slot) {
            $raw = self::string($request, $name);

            if ($raw === null) {
                continue;
            }

            $values[$name] = $raw;

            try {
                $slot = FilterInput::time($raw, $name);
            } catch (InvalidArgumentException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        unset($slot);

        try {
            // Named as the form names them; ExecutionFilter's own check
            // below would say createdAfter/createdBefore.
            FilterInput::assertWindow($since, $until, 'since', 'until');

            $filter = new ExecutionFilter(
                workflow: $workflows === [] ? null : $workflows,
                status: $statuses === [] ? null : $statuses,
                correlationId: $correlation,
                tenantId: $tenant,
                createdAfter: $since,
                createdBefore: $until,
            );
        } catch (InvalidArgumentException $exception) {
            // An empty window: both times parsed and the filter refused
            // the pair. Said, and the window dropped.
            $problems[] = $exception->getMessage();

            $filter = new ExecutionFilter(
                workflow: $workflows === [] ? null : $workflows,
                status: $statuses === [] ? null : $statuses,
                correlationId: $correlation,
                tenantId: $tenant,
            );
        }

        return new self($filter, $problems, $values);
    }

    /**
     * The filter fields as they were typed, for carrying through a
     * pagination link so the next page is a page of the same set.
     *
     * @return array<string, string|list<string>>
     */
    public function query(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * A repeatable field: `?status[]=failed&status[]=retrying`, or a
     * single `?status=failed`, or a comma-separated `?status=failed,retrying`
     * — all three are what a person or a link might produce.
     *
     * @param  list<string>  $problems  Added to when values past the cap are dropped.
     * @return list<string>
     */
    private static function list(Request $request, string $name, array &$problems): array
    {
        $value = $request->query($name);

        // The splitting is shared with the console (FilterInput); only
        // what happens past the cap is this surface's own.
        $out = FilterInput::values(is_array($value) ? $value : [$value]);

        if (count($out) > self::MAX_VALUES) {
            $problems[] = sprintf(
                'Only the first %d %s values were applied; the request carried %d.',
                self::MAX_VALUES,
                $name,
                count($out),
            );

            $out = array_slice($out, 0, self::MAX_VALUES);
        }

        return $out;
    }

    private static function string(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
