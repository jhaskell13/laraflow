# Limitations and design principles

What Laraflow deliberately does not do yet, and the principles that decided it.

## Current limitations

Laraflow is honest about what it does not yet do. These are the known gaps as of 0.7.0; what comes next lives in [ROADMAP.md](../ROADMAP.md).

- **No graph branching, and none planned before 1.0.** A plan is a flat, validated list of steps. A fan-out runs many workflows in parallel, but it is one step at one position, and the plan either side of it is as linear as it was; Laraflow does not aggregate member outputs, and a member cannot add members. Reduction is the step that follows.
- **Retries, retention, transport retries, and rate limits are all off by default.** `retry.max_attempts` ships as 1, every `retention.*` bound as null, `http.retries.max_attempts` as 1, and `http.rate_limit` as null; nothing is retried, removed, or throttled until you opt in. With retries on and retention off, the attempt-history table grows without bound.
- **Timeouts do not interrupt running PHP.** A step past its deadline is *classified* as timed out at the recovery boundary and charged to its retry budget, but a genuinely hung process is bounded only by the queue worker's timeout. Cancelling a running execution is likewise cooperative — the step in flight finishes first.
- **Side effects are at-least-once — and retries repeat them on purpose.** If a worker dies between a step's external call and its checkpoint, recovery runs the step again; a retry policy re-runs failing steps deliberately. Wrap them in `$context->remember()` so they run once per execution and a crash inside the window is raised rather than repeated ([idempotency.md](idempotency.md)). Transport retries follow the same honesty: a `POST` is repeated only on 429 or when you mark it `asIdempotent()`.
- **Schedules are minute-grained and declared in code.** A tick is a minute; seconds are refused. Schedules live on workflow classes and are activated in configuration — there is no runtime schedule editor, deliberately ([ROADMAP.md](../ROADMAP.md), authoring is a post-1.0 question). Tick counting is exact to ten thousand missed instants and records the bound beyond.
- **A trigger signature can be replayed inside its tolerance.** The window is bounded by the signal contract (a replay overwrites the same wait or is refused) and by `tolerance`; the one residual case and its defences are documented in [triggers.md](triggers.md#replay). Keys carry no audience: use a different key per environment.
- **Tenant ceilings are exact in the steady state, not at the instant.** Two claimants counting in the same moment can both pass, so a ceiling of *n* can briefly hold *n* plus the racers — the same honesty as the connector rate limit. And on MySQL, MariaDB, and SQL Server default collations, tenant ids differing only by case are one tenant to every tenant-scoped query ([tenancy.md](tenancy.md)).
- **Per-workflow counts come from the snapshot only.** The public query API filters and counts within one scope; it has no `GROUP BY`. The metrics snapshot counts unfinished and failed executions by workflow and status in one query ([metrics.md](metrics.md)); `laraflow:summary --workflow=` scopes its numbers to one workflow.
- **No write-time payload redaction.** Input seeds the context on every `execute()` and context is restored on recovery, so rewriting either at write time would corrupt re-runs. Store identifiers rather than secrets; scrub payloads after the fact with retention; redact error messages with `errors.redactor`.
- **Metrics are yours to wire.** Durations, queue lag, and connector call timings are on events, and current counts are in the metrics snapshot; turning them into Prometheus, StatsD, Datadog, or Pulse metrics is a few lines of listener or scheduled code you write, deliberately ([decision 0011](decisions/0011-one-optional-package.md)). Recipes are in [metrics.md](metrics.md) and [inspection-and-observability.md](inspection-and-observability.md#vendor-neutral-by-construction).
- **Two bundled connectors, both experimental.** GitHub and Slack exist to prove the contracts, not to cover their providers' APIs; they offer the generic request, error, and paging machinery plus a handful of helpers. Provider-specific conveniences are yours to add on top, and the connectors stay experimental until 1.0.
- **Connector tests prove shapes, not providers.** They fake HTTP and run without network, so they prove a connector handles the responses a provider documented — not that the provider still sends them. See [compatibility.md](compatibility.md#provider-apis).
- **Stack frames beneath a connector may carry the credential.** Laraflow's own frames are clean, but Laravel's HTTP client frames hold the authenticated options when `zend.exception_ignore_args` is off, as they would for any PHP HTTP client. See [SECURITY.md](../SECURITY.md).
- **`0.x`, so minor versions may break.** The schema is frozen and upgrades follow [upgrading.md](upgrading.md), but the public API may still move before 1.0; breaking changes are listed in [CHANGELOG.md](../CHANGELOG.md) under **Breaking**. Pin a version you have tested.
- **No production usage yet.** Everything here is proven by the test suite across five database engines and the CI matrix, not by having survived a real incident. Read [execution-lifecycle.md](execution-lifecycle.md) before depending on it for work you cannot afford to re-run.

## Design principles

**Recovery is part of the model.** Persisting a status is only the beginning. A workflow runtime must define what happens after a process disappears, how completed work is recognised, and what data is needed to continue.

**Be honest about side effects.** A framework cannot generally guarantee that an external API call happened exactly once if a worker crashes before recording its result. Laraflow makes retry and recovery behaviour explicit and gives developers tools to make repeatable operations safe.

**Keep workflows in application code.** Workflows should be versionable, reviewable, and testable with the rest of a Laravel application. No hosted service or visual designer is required to understand what an integration does.

**Grow from a reliable core.** Linear workflows and reliable recovery come before complex branching and large connector catalogs. The inspection dashboard came only once the query contract it reads through had been proven by five milestones of console commands.
