<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

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

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        protected array $data = [],
        protected int|string|null $executionId = null,
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
     * @internal Called by the runner before each step is executed.
     */
    public function enterStep(int $position, int $attempt): void
    {
        $this->stepPosition = $position;
        $this->attempt = $attempt;
    }
}
