<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Illuminate\Log\Context\Repository as ContextRepository;
use Laraflow\Integration\Connection;
use Laraflow\Workflow\StepExecution;
use Laraflow\Workflow\WorkflowExecution;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Publishes the identity of the running execution into Laravel's log
 * context, so every log record an application writes from inside a step
 * — through its own logger, with no Laraflow involvement — carries the
 * execution id, workflow, step, attempt, and correlation identifiers.
 *
 * Laravel dehydrates the context into queue payloads and rehydrates it
 * on the worker, so a job a step dispatches inherits these keys too and
 * one correlation id follows the work across processes.
 *
 * Every key is prefixed `laraflow.`: the context belongs to the
 * application, and a package writing bare `correlation_id` into it
 * would silently clobber whatever the application put there.
 *
 * Entries nest. A step that synchronously runs another workflow pushes
 * a second frame; leaving it restores the outer execution's keys rather
 * than deleting them, so the remainder of the outer run is still
 * attributed correctly.
 */
class LoggingContext
{
    public const PREFIX = 'laraflow.';

    /**
     * Every key this class owns. Restoring means putting exactly these
     * back as they were — nothing else in the context is touched.
     *
     * @var list<string>
     */
    public const KEYS = [
        self::PREFIX.'execution_id',
        self::PREFIX.'workflow',
        self::PREFIX.'correlation_id',
        self::PREFIX.'tenant_id',
        self::PREFIX.'step',
        self::PREFIX.'position',
        self::PREFIX.'attempt',
    ];

    /**
     * Keys published only while a connector request is in flight. Kept
     * out of KEYS so leaving an execution does not try to restore them
     * and entering one does not snapshot them: their lifetime is one
     * request, nested anywhere.
     *
     * @var list<string>
     */
    public const CONNECTOR_KEYS = [
        self::PREFIX.'connector',
        self::PREFIX.'connection',
    ];

    /**
     * What the connector keys held before the current request, innermost
     * last. A connector call can nest — a step calling a connector whose
     * transport calls another — so this is a stack like the frames.
     *
     * @var list<array<string, mixed>>
     */
    protected array $connectorFrames = [];

    /**
     * One snapshot per entered execution, innermost last.
     *
     * @var list<array<string, mixed>>
     */
    protected array $frames = [];

    public function __construct(
        protected ?ContextRepository $context = null,
    ) {}

    /**
     * Publish the execution's identity and remember what was there
     * before, so leave() can put it back.
     */
    public function enter(WorkflowExecution $execution): void
    {
        if ($this->context === null) {
            return;
        }

        $this->frames[] = $this->snapshot();

        $this->context->add([
            self::PREFIX.'execution_id' => $execution->id,
            self::PREFIX.'workflow' => $execution->workflow,
        ]);

        // Absent identifiers are left absent rather than written as
        // null: a null in every log record is noise that means nothing.
        $this->addIfPresent(self::PREFIX.'correlation_id', $execution->correlationId);
        $this->addIfPresent(self::PREFIX.'tenant_id', $execution->tenantId);
    }

    /**
     * Narrow the context to the step now running. Overwrites the
     * previous step's keys; they are cleared wholesale by leave().
     */
    public function enterStep(StepExecution $step): void
    {
        $this->context?->add([
            self::PREFIX.'step' => $step->step,
            self::PREFIX.'position' => $step->position,
            self::PREFIX.'attempt' => $step->attempts,
        ]);
    }

    /**
     * Name the connector and connection being talked to, for the
     * duration of one request, so every log record written while it is
     * in flight — the application's own included — says where it was
     * going.
     */
    public function enterConnector(Connection $connection): void
    {
        if ($this->context === null) {
            return;
        }

        $this->connectorFrames[] = $this->snapshotOf(self::CONNECTOR_KEYS);

        $this->context->add([
            self::PREFIX.'connector' => $connection->connector,
            self::PREFIX.'connection' => $connection->name,
        ]);
    }

    /**
     * Restore the connector keys. Called from a finally, so a failed
     * request leaves the context as it found it.
     */
    public function leaveConnector(): void
    {
        if ($this->context === null || $this->connectorFrames === []) {
            return;
        }

        $prior = array_pop($this->connectorFrames);

        foreach (self::CONNECTOR_KEYS as $key) {
            if (array_key_exists($key, $prior)) {
                $this->context->add($key, $prior[$key]);

                continue;
            }

            $this->context->forget($key);
        }
    }

    /**
     * Restore the context to exactly what it was before enter().
     *
     * Called from a finally block, so it runs whether the execution
     * completed, threw, scheduled a retry, or was cancelled. Without
     * it, an in-process run would leave its execution id attached to
     * every later log record of the surrounding request.
     */
    public function leave(): void
    {
        if ($this->context === null || $this->frames === []) {
            return;
        }

        $prior = array_pop($this->frames);

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $prior)) {
                $this->context->add($key, $prior[$key]);

                continue;
            }

            $this->context->forget($key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(): array
    {
        return $this->snapshotOf(self::KEYS);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function snapshotOf(array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            if ($this->context?->has($key)) {
                $snapshot[$key] = $this->context->get($key);
            }
        }

        return $snapshot;
    }

    protected function addIfPresent(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->context?->forget($key);

            return;
        }

        $this->context?->add($key, $value);
    }
}
