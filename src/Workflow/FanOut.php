<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * One member of a fan-out: the workflow to run as a child execution and
 * the input to start it with. Yielded by FanOutStep::members().
 *
 * The workflow is given as an instance so an author writes
 * `new FanOut(new NotifySubscriber, [...])`, the way a sub-workflow step
 * returns one; only its class is recorded, because a member may be
 * created on a later claim than the one that declared it.
 */
final readonly class FanOut
{
    /**
     * @param  array<string, mixed>  $input  Seeds the member's context; identifiers rather than payloads.
     */
    public function __construct(
        public Workflow $workflow,
        public array $input = [],
    ) {}

    /**
     * The durable form: class and input, nothing live.
     *
     * @return array{workflow: class-string<Workflow>, input: array<string, mixed>}
     */
    public function declaration(): array
    {
        return ['workflow' => $this->workflow::class, 'input' => $this->input];
    }
}
