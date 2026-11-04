<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

use InvalidArgumentException;

/**
 * @internal The named triggers an application registered, one per
 * slug. Lives on the container so it belongs to one application
 * instance and is gone with it — the dashboard's rule for its
 * authorization callback, for the same reason: a test must not leak a
 * trigger into the next.
 */
final class TriggerRegistry
{
    /** The signed endpoint's own prefix; no named trigger may shadow it. */
    public const RESERVED = 'executions';

    /** @var array<string, NamedTrigger> */
    private array $triggers = [];

    public function register(NamedTrigger $trigger): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $trigger->name) !== 1) {
            throw new InvalidArgumentException(
                "A trigger name must be a URL-safe slug — lowercase letters, digits, hyphens, underscores — got [{$trigger->name}]."
            );
        }

        if ($trigger->name === self::RESERVED) {
            throw new InvalidArgumentException(
                'The trigger name [executions] is reserved: it is the signed endpoint\'s own prefix.'
            );
        }

        if (isset($this->triggers[$trigger->name])) {
            throw new InvalidArgumentException(
                "A trigger named [{$trigger->name}] is already registered; a name is one endpoint."
            );
        }

        if (! class_exists($trigger->verifier) || ! is_subclass_of($trigger->verifier, TriggerVerifier::class)) {
            // A named trigger with no verifier is not registerable: the
            // provider's signature check is the point of the method.
            throw new InvalidArgumentException(
                "The verifier for trigger [{$trigger->name}] must be a class implementing ".TriggerVerifier::class
                .", got [{$trigger->verifier}]."
            );
        }

        $this->triggers[$trigger->name] = $trigger;
    }

    public function find(string $name): ?NamedTrigger
    {
        return $this->triggers[$name] ?? null;
    }
}
