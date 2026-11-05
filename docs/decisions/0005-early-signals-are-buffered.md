# 0005 — A signal that arrives early is kept, not refused

**Status:** Accepted (0.6.0).

## Context

A step starts an external process — a payment, an approval request — and parks on a signal for its answer. The provider's webhook can arrive before the step has finished parking. Refusing it meant the workflow waited until its timeout whenever the provider did not retry.

## Decision

A signal for an unfinished execution that is not yet waiting on that name is kept in a signal inbox, one per execution and name, latest delivery winning. Parking consumes a matching buffered signal and resumes at once, in the same transaction that writes the park. Signals for finished executions are still refused, and the response says whether a signal resumed a step or was buffered ([advanced-workflows.md](../advanced-workflows.md)).

## Rejected

- **Refusing and relying on the caller to retry.** Pushes a race the package can close onto every integration.
- **Keeping every delivery.** Unbounded, and no step consumes more than one value per wait.

## Consequences

A late duplicate of a signal sent while an execution is running is buffered and will answer a later wait with the same name; signal names should be specific to the wait they answer.
