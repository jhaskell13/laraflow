# 0001 — Execution is at-least-once; idempotency is a tool, not a promise

**Status:** Accepted (Milestone 1; restated at Milestone 8 when the idempotency store shipped).

## Context

A step that calls an external service and then crashes before its result is checkpointed cannot know whether the call landed. Every durable-execution system faces this boundary; the honest ones say so.

## Decision

Laraflow guarantees that a checkpointed step never runs again, and that a step interrupted before its checkpoint runs again on recovery. It does not claim exactly-once for side effects. It ships tools that make at-least-once safe — `$context->remember()`, `$context->idempotencyKey()`, and the idempotency store — and documents where they apply ([execution-lifecycle.md](../execution-lifecycle.md), [idempotency.md](../idempotency.md)).

## Rejected

- **Claiming exactly-once.** Not achievable for arbitrary side effects without the remote system's cooperation; a package that claims it is wrong in the case that matters.
- **Distributed transactions with external services.** Out of reach for HTTP APIs, and not a Laravel-shaped answer.

## Consequences

Step authors must make external calls idempotent or wrap them. The README and the contract documents say this up front; "Out of scope through 1.0" in the roadmap repeats it.
