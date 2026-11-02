# The idempotency store

> This document was written as the design contract for Milestone 8's idempotency store before any of it was built, and is now the shipped contract. The enforcing suite is `tests/Feature/Workflow/IdempotencyTest.php`. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.

## The problem this solves

Laraflow guarantees each step's checkpoint commits at most once. It cannot guarantee the same of the step's side effects: a worker that dies between charging a card and committing its checkpoint leaves a step that will run again. That window is stated plainly in [execution-lifecycle.md](execution-lifecycle.md#persistence-boundaries-and-the-at-least-once-contract) and no framework can close it for arbitrary external calls.

What exists today is `$context->idempotencyKey()` — a stable string an application can pass to a provider that supports idempotency keys, or use to deduplicate in its own tables. That is a hook, not a solution: every application that needs it writes the same table, the same read-before-write, and the same ambiguity handling, and most write the last part wrong.

The store is that machinery, once, with the ambiguity made explicit:

```php
$chargeId = $context->remember('charge', fn () => $payments->charge($order, $amount));
```

The closure runs at most once per execution, across every retry, every recovery, and every worker — unless the application, through a reconciler, asserts that a run left nothing behind. **It does not make side effects exactly-once** — see [What this is not](#what-this-is-not). It makes the irreducible window *detectable*, which is the part an application cannot build safely on its own.

## The API

```php
public function remember(string $key, Closure $work, ?Closure $reconcile = null): mixed
```

- **`$key`** is stored as written and scoped to the step by the record — `(execution, step position, key)` — not the attempt. Two steps may both use `'charge'` without colliding; the same step retried finds its own record. A key must be non-empty, valid UTF-8, at most 255 characters, free of control characters, and free of leading or trailing whitespace (which MariaDB's default collation ignores when comparing); `InvalidIdempotencyKeyException` otherwise. And it must be a key the database can tell apart from the ones already recorded: MySQL, MariaDB, and SQL Server compare strings case-insensitively by default, so `'Charge'` after `'charge'` is refused by name on those engines rather than treated as a replay of work it never asked for. SQLite and PostgreSQL compare bytes and hold both.
- **`$work`** is the side effect. Its return value is persisted and must satisfy the serialization contract; `null` is a valid value and is distinguished from "no value".
- **`$reconcile`** is optional and is consulted only in the uncertain state described below.

## The four states

A record exists per `(execution, key)`. What `remember()` does depends on what it finds:

| Found | Meaning | Behaviour |
|---|---|---|
| **Nothing** | The work has never begun. | Write a `pending` marker, **commit it**, run `$work`, persist the value, return it. |
| **`settled`** | The work completed and its result is durable. | Return the stored value. `$work` does not run. |
| **`failed`** | The work threw, and the throw was a known outcome. | Release the record — only a `failed` record can be released — and begin again. If another attempt moved it first, read what it left; after three such collisions, raise. |
| **`pending`** | The work began and the process never came back. | **The outcome is unknown.** Reconcile if a reconciler was given; otherwise raise. |

**The marker commits before the work runs.** This ordering is the entire design. Without it a crash mid-side-effect is indistinguishable from a crash before it, and the store could only ever guess. With it, a `pending` record is positive evidence that the work *started* and no evidence that it finished.

**The value commits after, in its own transaction, outside the step checkpoint.** A remembered value is not part of the step's output and does not ride its checkpoint: the point is that it survives the step failing. A step that charges a card and then throws on the next line keeps the charge id, and the retry skips the charge.

## Uncertainty is raised, not guessed

Finding a `pending` record means a previous attempt may or may not have completed the side effect. Laraflow refuses to decide:

```
IdempotentOutcomeUncertainException: Step [ChargePayment] at position 1 began idempotent work
[charge] at 2026-11-01 09:14:02 and no outcome was recorded. The side effect may or may not
have taken place. Supply a reconcile callback, or resolve it manually.
```

This is deliberately an interruption rather than a silent re-run. Silently re-running is how a customer is charged twice; silently skipping is how they are never charged at all. The exception is a terminal step failure by default — it is in `StepPolicy::NEVER_RETRY`, because retrying finds the same `pending` record and raises again.

**Reconciling resolves it.** When the provider can be asked what happened, say so:

```php
$chargeId = $context->remember(
    key: 'charge',
    work: fn () => $payments->charge($order, $amount, key: $context->idempotencyKey()),
    reconcile: fn () => $payments->findChargeByKey($context->idempotencyKey()),
);
```

The reconciler runs only in the uncertain state. A **non-null** return is treated as the outcome: it is settled and returned, and the work does not run again. A **null** return asserts that the side effect provably did not land, and the work runs from the start. That assertion is the application's to make — Laraflow has no way to verify it, and a reconciler that returns null while the charge exists will charge twice. A reconciler that throws leaves the record `pending` and the step fails with the reconciler's exception.

## A thrown exception is a known outcome; a dead process is not

When `$work` throws, Laraflow gets to run code, so it records what it knows: the record is marked `failed` with the exception class, and the exception propagates to fail the step under its ordinary retry policy. The next attempt deletes the `failed` record and runs the work again — the correct behaviour when a request was rejected before it took effect.

That is the right default and it is wrong for one important case: a client timeout on a request the server processed anyway. There the exception means *unknown*, not *failed*. An exception says so by implementing the marker interface:

```php
use Laraflow\Exceptions\IndicatesUncertainOutcome;

class GatewayTimeoutException extends RuntimeException implements IndicatesUncertainOutcome {}
```

An exception implementing it leaves the record `pending`, so the next attempt takes the uncertain path and reconciles or raises. The pattern mirrors `SuggestsRetryDelay`, which already lets an exception tell the retry machinery something only it knows.

## Two workers cannot both run the work

`(workflow_execution_id, key)` is unique in the database, and the marker is written as an insert. When a stalled worker and the worker that reclaimed its execution are both inside the same step, exactly one insert succeeds. The loser does not run the work: it reads the winner's record and finds `pending`, which is the uncertain state, and raises rather than duplicating the side effect.

This matters because the stalled worker's *claim* check cannot help here. The claim is verified when Laraflow writes step state — after the side effect. The unique constraint is what stands between two live workers and two charges, and it is enforced by the database rather than by timing.

The same discipline covers the other states. `settle()` and `fail()` act only on a `pending` record, so the first outcome recorded is the one kept; `release()` removes only a `failed` record and reports whether it did, so two attempts that both found a failure cannot both begin again — the second finds the first's pending marker and takes the uncertain path. Each of these is a predicate in the statement, not a check before it.

## Persistence

One additive migration creates `laraflow_idempotency_keys`:

| Column | Notes |
|---|---|
| `id` | |
| `workflow_execution_id` | Foreign key, cascading. Scopes the record and drives deletion. |
| `step_position` | Which step owns it. Part of the key: two steps may remember the same name. |
| `key` | The key as the step wrote it. |
| `status` | `pending`, `settled`, `failed`. |
| `value` | `json`, nullable — the settled result. Null here is a stored null when `status` is `settled`. |
| `error_class` | Set with `failed`. |
| `started_at`, `settled_at` | `settled_at` is set by `settle()` and by `fail()` alike: when the record stopped being pending. |
| `timestamps` | |

`unique(workflow_execution_id, step_position, key)` is the concurrency guarantee above, and the table's only index: leading with the execution id, it also serves every read by execution — the count behind the cap, `laraflow:show`, retention's scrub and orphan deletes — so the foreign key needs no index of its own on any engine. A custom `IdempotencyStore` is held to the same guarantees by `Laraflow\Testing\IdempotencyStoreContract`, which exists because the natural Eloquent idiom for `begin()` — find-or-create, return the row — would report every *first* call as an uncertain outcome.

**Bounded per execution.** `laraflow.idempotency.max_keys_per_execution` (default 100, at least 1) limits how many distinct keys one execution may record. A loop calling `remember()` with a generated key is a bug that would otherwise grow a table without bound; past the cap the step fails with `TooManyIdempotencyKeysException` and the marker it had just written is released. The cap is checked after the insert, so a replay of a key that already exists is never refused at the cap, and two concurrent attempts can overshoot it by at most one. Raise it deliberately if a workflow genuinely needs more.

**Values obey the serialization contract.** The same JSON-only rule and the same `laraflow.execution.max_payload_bytes` bound that govern step output govern a remembered value, checked before the write so an oversized or unserializable value fails by name rather than as a driver error. The record is then left `pending` on purpose: the side effect *did* happen and its value cannot be kept, so the honest state for the next attempt is "unknown — reconcile", not "never ran". A reconciler's answer is held to the same rule.

## Retention

Idempotency records are payload, and they follow payload:

- **Scrub** clears `value` (and leaves the record, its key, and its status) for `completed` and `cancelled` executions. A scrubbed record still prevents the work from re-running; it just no longer remembers the answer.
- **Scrub never touches `failed` executions**, for exactly the reason it never touches their input and context: a failed execution is re-executable, and re-executing it against discarded remembered values would re-run side effects the record says already happened. The rule in [retention.md](retention.md#scrub-never-touches-failed-executions-and-there-is-no-knob-to-make-it) extends here unchanged.
- **Delete** removes the records with the execution, through the same orphan-safe path the attempts table uses — by asking which records no longer have a parent, not which belong to the ids the scan asked about, so an execution the guard spared keeps its records on every engine with or without cascades.
- **Attempt pruning** does not touch them. They are not attempt history.

## Observability

`laraflow:show --attempts` lists the execution's idempotency records: step, key, status, when the work began and when it stopped being pending, and the exception class of a known failure. **Values are never printed** — they are results of side effects and may carry anything. A `pending` record is highlighted, because an execution carrying one is an execution with an unresolved outcome.

No lifecycle event fires for a remembered value. Events carry metadata and never payloads, and an event saying only "a key settled" would be noise on the hot path of every step.

## What this is not

- **Not exactly-once.** The side effect can still happen twice: once by a worker that died after the provider processed it and before the value committed, and once by a reconciler that wrongly reports nothing happened. What the store adds is that the first case is *detected* and surfaced rather than silently repeated. "At-least-once" becomes "at-least-once, and you are told when once is in doubt".
- **Not a cache.** There is no TTL, no eviction, and no sharing between executions. Two runs of the same workflow over the same order have different execution ids and therefore different keys. Deduplicating *across* executions is an application concern and wants the application's own table.
- **Not a lock.** The unique constraint stops two workers both starting the work; it does not make the second one wait for the first. The second is told the outcome is uncertain.
- **Not for transactional work.** Work already covered by a database transaction in the same database needs none of this — commit it with the checkpoint instead.

## Using it correctly

**Do not call `remember()` inside an application transaction.** The marker must commit before the work runs, and a surrounding transaction defers that commit until after — destroying the ordering the whole design rests on. If the work must be transactional, open the transaction inside the closure.

**A context built by hand has no store.** The runner constructs the context with an `IdempotencyResolver` — the state machine over the store, Public for this one reason — and a `new WorkflowContext([...])` in a unit test does not have one. Calling `remember()` on such a context raises `IdempotencyStoreUnavailableException` naming the cause rather than silently running the closure. The resolver is nullable on `WorkflowContext` for this reason alone: the context is a value object, not a service, and this is a documented "off" state like `LoggingContext` without a context repository.

**Inside an application transaction, PostgreSQL has one more reason to object.** A failed statement aborts a PostgreSQL transaction, so the store's marker insert runs in its own savepoint; that keeps a replay of an existing key legal inside your transaction, but it does not restore the ordering above. Keep `remember()` outside your transactions.

**Prefer a provider's own idempotency key when it has one.** `remember()` and a provider-side key solve overlapping halves of the problem: the provider's key makes the *duplicate request* harmless, and the store makes the *duplicate attempt* unnecessary and its ambiguity visible. Used together — as in the reconcile example above — the uncertain window is both narrow and resolvable.

## Compatibility notes

- `WorkflowContext::remember()` is additive, and the constructor gains a trailing optional `IdempotencyResolver`.
- One new table, additive, under the freeze.
- `laraflow.idempotency.max_keys_per_execution` is a new configuration key with a default; nothing changes for an application that never calls `remember()`.
- `IdempotentOutcomeUncertainException`, `InvalidIdempotencyKeyException`, `TooManyIdempotencyKeysException`, `IdempotencyStoreUnavailableException`, `IndicatesUncertainOutcome`, the `IdempotencyStore` contract with `IdempotencyRecord` and `IdempotencyStatus`, `IdempotencyResolver`, and `Laraflow\Testing\IdempotencyStoreContract` are new Public names. The default store binding is swappable through the container like every other Laraflow contract.

## Decided in step 8, implemented in step 10

Everything above is built and enforced by `IdempotencyTest`, including a worker dying on either side of the work — right after the marker committed, and between the side effect and the value — a second worker losing the race on the unique key, two attempts racing to release a failed record, a reconciler answering each way and throwing, and keys the database cannot tell apart.
