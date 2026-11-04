# Tenant isolation and throttling

> This document was written as the design contract for Milestone 10's per-tenant ceilings before any of it was built, and is now the shipped contract. The enforcing suite is `tests/Feature/Workflow/TenantThrottlingTest.php`. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.
>
> The configuration this document names is shipped and held to it by the contracts test.

## The problem this solves

A multi-tenant application runs every tenant's workflows through one worker pool. Tenants are already *identified* — `ExecutionOptions::$tenantId` is recorded on every execution, inherited by every child, carried on every event, and scopes every scan and command ([inspection-and-observability.md](inspection-and-observability.md#correlation)) — but nothing *bounds* them. One tenant that imports a million rows, fans out a thousand members, or simply has more customers than the rest fills the queue, and every other tenant's approval, refund, and report waits behind it. Laravel's queue has no notion of a tenant; its workers take the next job, and the next job is the flooding tenant's for as long as the flood lasts.

The connector layer has the same problem one level down, and Milestone 10 opened by closing it there: a client-side rate limit may be scoped per tenant of the running execution ([connectors.md](connectors.md#retries-and-rate-limits)). This document is the same idea at the level above — **a ceiling on how much of the pool one tenant may hold at once, and on how fast it may claim** — enforced at the one gate every run passes through.

## Shared principles

- **Enforced at claim time.** Every run of an execution, on any worker, from any path — a fresh dispatch, a due retry, a resumed wait, a heartbeat, recovery — begins with `ExecutionRepository::claim()`. A ceiling checked there is checked everywhere, and nothing else needs to know it exists.
- **Off by default, like every other authority.** No ceiling applies until the application states one. An execution created without a tenant is never bounded by this contract: there is no tenant to bound it by, and a default ceiling that silently applied to untagged work would make `tenantId` mandatory by the back door.
- **Nothing is dropped, and the record explains the wait.** A claim refused by a ceiling changes nothing about the execution's status; it stays `pending`, `retrying`, or `waiting` as it was, with a note of when it was last deferred and how often, so an operator reading the record sees *waiting for a tenant slot* and not a dispatch that went missing.
- **Honest about the instant, exact in the steady state.** The check is a count followed by the compare-and-set claim, not one atomic operation. Two claimants for the same tenant in the same instant can both count below the ceiling and both claim, so a ceiling of *n* may briefly hold *n* plus the number of workers that raced. The connector rate limit already states the same honesty; this document does too, and says why the alternative was refused ([Why not exact](#why-not-exact)).
- **The fairness rule is written down** and is a statement about sizing, not a promise the package can keep on its own ([The fairness rule](#the-fairness-rule)).

## Configuration

```php
// config/laraflow.php
'tenants' => [
    // The most executions one tenant may have running at once. Null: no ceiling.
    'max_concurrent' => env('LARAFLOW_TENANT_MAX_CONCURRENT'),

    // The most claims one tenant may make per minute. Null: no ceiling.
    'max_claims_per_minute' => env('LARAFLOW_TENANT_MAX_CLAIMS_PER_MINUTE'),

    // Seconds a refused execution waits before its delivery is tried again.
    'deferral' => env('LARAFLOW_TENANT_DEFERRAL', 15),

    // Per-tenant exceptions to the two ceilings above.
    'overrides' => [
        // 'acme' => ['max_concurrent' => 20],
        // 'trial-tenant' => ['max_concurrent' => 1, 'max_claims_per_minute' => 10],
    ],
],
```

The two ceilings are read through a contract, `TenantPolicy` — `limitsFor(string $tenantId): TenantLimits` — whose default implementation reads this section. An application whose tenants are rows, not configuration entries, binds its own and answers from wherever a tenant's plan lives; the policy is consulted on every claim, so a change takes effect on the next one, exactly as a `CredentialProvider` is consulted per request. `TenantLimits` is a value object of two nullable integers, validated at construction (a ceiling below 1 is refused).

Both ceilings are *per tenant*, not *per tenant per workflow*; a tenant's fan-out members, sub-workflow children, compensation passes, and fresh starts all draw on the same slots, because the pool they contend for is the same.

## What a ceiling counts

**`max_concurrent`** counts the tenant's executions that *hold a worker*: status `running` or `compensating`, with a claim fresher than `execution.stale_after`. A stale claim is a dead worker's ([execution-lifecycle.md](execution-lifecycle.md#claiming-and-concurrency)), and a dead worker holds no slot — counting it would let one crash lock a tenant out until recovery happened to reclaim it. Executions that are `pending`, `retrying`, or `waiting` hold nothing and count for nothing: a parent parked on a join of a thousand members occupies no slot while its members run, and a tenant with ten thousand pending executions is bounded by how many *run*, not by how many exist. The count is one indexed query, served by a new `(tenant_id, status, claimed_at)` index on the executions table.

**`max_claims_per_minute`** counts successful claims per minute through Laravel's cache-backed rate limiter — a fixed window opened by the tenant's first claim, not a sliding one, so up to twice the ceiling can pass across a window boundary — keyed per tenant, shared by every worker: the same limiter the connector throttle uses, with the same best-effort honesty. It bounds churn rather than occupancy: a tenant whose executions each take a hundred milliseconds could cycle through a modest `max_concurrent` fast enough to monopolise the pool by turnover, and this is the ceiling that stops it.

A tenant with no override uses the section's defaults; an override replaces only the keys it names; a null anywhere means no ceiling of that kind.

## What happens at the ceiling

`claim()` consults the policy for the execution's tenant before its compare-and-set `UPDATE` — and only for a row that claim could actually take. A duplicate delivery for a running, finished, or not-yet-due execution is refused as exactly that (`ExecutionAlreadyClaimedException`, `ExecutionNotRunnableException`, and the rest), ceilings never consulted: deferring work that cannot run would stamp the record and re-queue a claim that can never land. When either ceiling would be exceeded, the claim is **refused without a write to the claim columns**: `TenantThrottledException`, a `WorkflowException` naming the tenant, the ceiling, and the count, and implementing `SuggestsRetryDelay` with the configured `deferral`. The exception is not a failure of the execution and is never recorded as one — no step attempt, no `failed_at`, no event on the execution's state. Two things *are* written, in one counted `UPDATE` guarded on the status the ceiling counted under — a claim that landed in between cleared the columns and must not have a stale deferral stamped over its run; when the guard matches nothing, nothing is written or thrown and the compare-and-set decides what the row is now: `deferred_until` (now plus the deferral, plus up to a fifth of it in jitter derived from the execution id so a tenant's deferred work does not all wake at once) and `deferrals` (one more). The first successful claim clears both.

Who sees the refusal depends on who asked:

| Caller | Behaviour |
|---|---|
| **`ExecuteWorkflowJob`** | Catches it, re-pushes itself for `deferred_until` through the dispatcher — a *deferral*, not a queue retry: the job returns normally, `tries` is untouched, and the queue gains no retry authority — and logs at `info`. On the `sync` driver, where a delayed push would run inline at once, the exception propagates to the dispatcher's caller as a refusal. |
| **`WorkflowRunner::execute()` called directly** | Throws it. An application running executions in-process is told the tenant is at its ceiling and decides; `run()` is the same. |
| **`laraflow:execute`, the dashboard's execute action** | Reports the refusal in the runtime's words, as they report `ExecutionAlreadyClaimedException` today. There is no operator override: the ceiling protects *other* tenants, and an operator who needs one execution through raises the ceiling, which is a decision with the right scope. |
| **`laraflow:recover`** | Its scans skip an execution whose `deferred_until` has not passed, and a refusal that happens anyway — a ceiling reached between the scan and the claim — is reported as `deferred (tenant at ceiling)` and counted as skipped. A recover run never counts a deferral as a failure. |

**Deferral is delivery, not state.** An execution deferred ten times is still `pending` with `next_retry_at` null, or `retrying` with its own due time, or `waiting`. Every recovery scan — stale, due retries, due waits, the opt-in pending scan — and the scheduler's own re-dispatch heal skip an execution whose `deferred_until` is in the future: its own timer owns the next attempt, and a scan that kept finding it would start one more job per run, each only to be turned away, for exactly as long as the ceiling held.

A deferred job that finds its tenant *still* at the ceiling defers again, on the same jittered delay. The deferral has no upper bound on count: a tenant at its ceiling for an hour has its waiting work checked every fifteen seconds for an hour, which costs one count query per check, and the alternative — abandoning the work — is the one thing this contract promises not to do. `laraflow:show` and the dashboard show `Deferred ×12, next attempt 09:14:30 (its tenant is at a ceiling)` on such an execution, and `laraflow:summary` and the overview surface the deferred set (up to one page of it) as an attention line, because a tenant pinned at its ceiling is something to know.

## The fairness rule

The package can promise what the ceilings mean; what they *achieve* depends on how they are set against the pool. The rule, stated once:

> A ceiling is the most of the pool one tenant may hold at once. Below it, work is claimed first come, first served across tenants; at it, that tenant's further work waits for one of its own executions to rest, and no other tenant is affected by the wait. **Tenant B cannot be starved by tenant A if A's ceiling is below the number of workers**, because A can never hold every worker. If every tenant that can flood has a ceiling, and the ceilings of any set of tenants that might flood together sum to less than the pool, there is always a worker left for the rest.

Two consequences an operator should know:

- **A tenant without a ceiling can still starve the others.** `max_concurrent` under `tenants` applies to every tenant that has no override, which is how an application bounds all of them in one line; a null there bounds only the tenants named in `overrides`.
- **Within a tenant, order is approximate.** Deferred executions are redelivered by their own timers, not from a queue, so the first deferred is not guaranteed the first slot. `laraflow:recover`'s scans are oldest-first and nudge toward it; the package does not promise FIFO inside a tenant, because promising it would mean a per-tenant queue, which is the thing this contract exists to avoid building.

The exit criterion is this rule under test: two tenants share a pool, one floods, the other's work completes within a bound that does not grow with the size of the flood.

## Why not exact

An exact ceiling needs the count and the claim to be one atomic decision for everyone claiming under the same tenant. The ways to get that are a per-tenant lock held across every claim (a serialisation point on the hottest path in the package, and a lock the connector throttle already declined for the same reason), a per-tenant counter row incremented in the claim transaction (which drifts on every worker death and needs its own reconciliation scan), or a correlated subquery in the claim's `UPDATE` (which MySQL refuses against the table being updated, and which under read-committed isolation on every other engine still lets two concurrent updates both read the pre-update count). None of them makes the instant exact on every engine; all of them cost something on every claim. The ceiling is therefore stated as a bound on the steady state, exceeded at the instant by at most the number of workers that raced, and the exit criterion is fairness rather than a cap that holds to the unit.

## Interactions

- **Children inherit the tenant**, so a fan-out of a thousand members under a ceiling of five runs five at a time, each member deferring until a slot frees; the parent's join heartbeat is a claim too, and is bounded the same way. A sub-workflow's parent, parked, holds no slot.
- **Compensation** runs under a claim and counts as running. An undo pass is not exempt: it is work, and a tenant's undo storm is a storm.
- **Retention, versioning, idempotency** are unaffected: none of them claims.
- **Schedules** start executions with the schedule's tenant when it declares one ([schedules.md](schedules.md#declaring-a-schedule)); a scheduled execution is bounded as any other.
- **The connector rate limit's tenant scope** is independent: that bounds requests to a provider per tenant, this bounds workers per tenant, and an application may set either without the other.

## Observability

- **`WorkflowExecutionDeferred`**, after the counted write that records the deferral — execution id, workflow, correlation and tenant identifiers, which ceiling (`concurrency` or `rate`), the count against it, and `deferredUntil`. Identifiers and numbers only.
- **`laraflow:show`** and the execution page show the deferral count and next attempt when either is set; both are cleared by the first successful claim, so a finished execution carries no stale note.
- **`laraflow:summary`** and the overview count executions whose `deferred_until` is in the future, scoped as everything else is, and name them as needing attention.
- **The log context** carries the tenant already; the deferral log line at `info` carries the ceiling and the count.

## What this is not

- **Not data isolation.** Every tenant's executions share the same tables, scoped by a column. A tenant-per-database application points Laraflow at the tenant's connection as it points everything else, outside this contract.
- **Not collation-proof.** On MySQL, MariaDB, and SQL Server default collations, tenant ids that differ only in case or accents are one tenant to every tenant-scoped SQL — the ceilings' concurrency count, the listing filters, retention, bulk cancel. Mint tenant ids that do not rely on case to differ, as the correlation guidance already advises for identifiers generally; byte-exact enforcement at every query is a boundary deliberately not moved in this milestone, recorded here the way the connector host guard's is.
- **Not per-tenant queues**, and not priority. There is one pool; the ceilings bound shares of it. A tenant that must never wait behind another is a separate queue connection, which Laravel already provides and `laraflow.queue.*` already selects.
- **Not a quota.** Ceilings bound concurrency and claim rate, not how many executions a tenant may create in a month. Creation is the application's call to `start()`, and bounding it is the application's.
- **Not exact at the instant.** See above.

## Compatibility notes

- `ExecutionRepository::claim()` gains the ceiling check and may throw `TenantThrottledException`; a custom repository must honour both — a **Breaking** entry when it ships. The exception is new public surface, as are `TenantPolicy`, `TenantLimits`, and the event.
- One additive migration: `deferred_until` and `deferrals` on the executions table, the `(tenant_id, status, claimed_at)` index that serves the count, and an index on `deferred_until` so the summary's "what is deferred right now" is a scan with an index behind it, as every scan must be. `ExecutionRepository` gains `deferred()` for that read. The freeze and upgrade harness apply as always.
- A new `tenants` configuration section — `max_concurrent`, `max_claims_per_minute`, `deferral`, `overrides` — arriving with the step that reads it. Every value ships null or inert: nothing is bounded until an application says so, and `ProjectContractsTest` will hold the defaults to that.
- `laraflow:execute --now` continues to mean *ignore the retry schedule*; it does not mean *ignore the ceiling*.

## Decided here

The one-way doors, fixed by this document:

1. Ceilings are enforced in `claim()` and nowhere else, so every path to a run is bounded by one check.
2. An execution without a tenant is never bounded; a default ceiling applies to tenants, not to the absence of one.
3. `max_concurrent` counts fresh claims in `running` or `compensating` only; parked, pending, and stale executions hold no slot.
4. A refused claim changes no status and records no failure; it records the deferral and nothing else, and the job defers itself without spending a queue try.
5. There is no operator override for a ceiling: raising it is the override, at the right scope.
6. The ceiling is honest about the instant and exact in the steady state; the exit criterion is fairness, stated as a sizing rule.
7. Within a tenant, order is approximate, because exact order is a per-tenant queue and this contract refuses to build one.

## Enforcement

- `tests/Feature/Workflow/TenantThrottlingTest.php` — the roadmap's exit criterion: two tenants, a pool of simulated workers, one tenant flooding, the other's executions all completing within a bound independent of the flood's size, on every engine. Also: the count excluding parked, pending, and stale executions; a claim the predicate would refuse never deferred; the refusal writing the deferral and nothing else; the job re-pushing for `deferred_until` with the fresh payload's tries untouched; the sync driver surfacing the refusal to the caller; a child's deferral parking its parent rather than failing the step; `laraflow:recover` reporting a deferral as skipped; a custom `TenantPolicy` consulted per claim; the rate ceiling through the limiter; a tenant without a ceiling unbounded; an execution without a tenant unbounded; every recovery scan waiting out a deferral.
- `tests/Feature/ProjectContractsTest.php` — every `tenants` default ships inert.
- `tests/Feature/Database/SchemaFreezeTest.php` and `UpgradePathTest.php` — the two columns and the index.
