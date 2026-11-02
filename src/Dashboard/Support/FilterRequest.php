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

        foreach (self::list($request, 'status') as $status) {
            try {
                $statuses[] = FilterInput::status($status);
            } catch (InvalidArgumentException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        if ($statuses !== []) {
            $values['status'] = array_map(fn (ExecutionStatus $status): string => $status->value, $statuses);
        }

        $workflows = self::list($request, 'workflow');

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
     * @return list<string>
     */
    private static function list(Request $request, string $name): array
    {
        $value = $request->query($name);
        $values = is_array($value) ? $value : [$value];

        $out = [];

        foreach ($values as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            foreach (explode(',', (string) $item) as $piece) {
                $piece = trim($piece);

                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function string(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
