<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

use DateTimeImmutable;

/**
 * Key/value data shared between the steps of one execution.
 *
 * The context is seeded from the workflow input, persisted after every
 * completed step, and restored before remaining steps run on recovery.
 * Values must be JSON-serializable (see docs/execution-lifecycle.md).
 *
 * The context also exposes execution metadata so steps can build
 * idempotency keys for side effects that may repeat after a crash.
 */
class WorkflowContext
{
    protected ?int $stepPosition = null;

    protected int $attempt = 0;

    protected ?DateTimeImmutable $deadline = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        protected array $data = [],
        protected int|string|null $executionId = null,
        protected ?string $correlationId = null,
        protected ?string $tenantId = null,
    ) {}

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * A stored null is a value, not an absence: it is returned as null
     * rather than falling back to the default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * The id of the execution this context belongs to, or null when the
     * context was constructed outside a runner (for example in a unit test).
     */
    public function executionId(): int|string|null
    {
        return $this->executionId;
    }

    /**
     * The correlation identifier recorded on this execution, or null if
     * the application supplied none.
     *
     * Forward it to external services (a request id header, a provider's
     * metadata field) so one identifier ties your application's logs,
     * this execution, and the provider's records together.
     */
    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * The tenant identifier recorded on this execution, or null if the
     * application supplied none.
     *
     * This is the tenant the execution was *created* for. It is durable,
     * so a step reading it after recovery gets the original tenant
     * rather than whatever ambient tenant context the recovering worker
     * happens to be in.
     */
    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Position of the step currently being executed.
     */
    public function stepPosition(): ?int
    {
        return $this->stepPosition;
    }

    /**
     * Attempt number of the current step, starting at 1.
     */
    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * A key identifying the current logical step invocation, stable across
     * crashes and retries of the same step in the same execution. Use it to
     * deduplicate external side effects that may repeat under the
     * at-least-once contract.
     */
    public function idempotencyKey(): string
    {
        return sprintf('laraflow:%s:%s', $this->executionId ?? 'unpersisted', $this->stepPosition ?? 0);
    }

    /**
     * Deadline of the current attempt (the step's persisted timeout_at),
     * or null when the step declares no timeout.
     *
     * Laraflow does not interrupt a running step; this is how a step
     * bounds itself. Pass it to your HTTP client or check it in a loop —
     * a step that respects its own deadline never times out from
     * Laraflow's point of view.
     */
    public function deadline(): ?DateTimeImmutable
    {
        return $this->deadline;
    }

    /**
     * Whole seconds until the deadline, floored at zero, or null when
     * the step has none. $now exists for tests; it defaults to the
     * actual present because the remaining budget is inherently about
     * real wall-clock time.
     */
    public function remainingSeconds(?DateTimeImmutable $now = null): ?int
    {
        if ($this->deadline === null) {
            return null;
        }

        $now ??= new DateTimeImmutable;

        return max(0, $this->deadline->getTimestamp() - $now->getTimestamp());
    }

    /**
     * @internal Called by the runner before each step is executed.
     */
    public function enterStep(int $position, int $attempt, ?DateTimeImmutable $deadline = null): void
    {
        $this->stepPosition = $position;
        $this->attempt = $attempt;
        $this->deadline = $deadline;
    }
}
