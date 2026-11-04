# Compatibility and support policy

This policy states which platform versions Laraflow supports, what "supported" means at this stage of the project, and how support is dropped.

## Supported versions

| Component | Versions | Status |
|-----------|----------|--------|
| PHP | 8.2, 8.3, 8.4, 8.5 | Every version runs in the GitHub Actions matrix (`.github/workflows/ci.yml`). |
| Laravel | 12.x, 13.x | Declared via `illuminate/*` `^12.0|^13.0`; tested with Orchestra Testbench 10 and 11. CI runs both majors on SQLite across every supported PHP version, plus one `prefer-lowest` cell (Laravel 12 on PHP 8.2) that resolves every constraint to its floor, so the floor is proven rather than assumed. Each server engine version below runs both ends of that axis: Laravel 13 on the lock file, and Laravel 12 at the floor on PHP 8.2. |
| SQLite | 3.x | Tested: the suite's default (in-memory). |
| MySQL | 8.4+ | Tested: the full suite runs against MySQL 8.4 (LTS) in CI, on both Laravel majors, and the install walk ends the job against it. The 9.x innovation releases are not a support target. |
| MariaDB | 10.11+ | Tested: the full suite runs against MariaDB 10.11 and 11.4 in CI, on both Laravel majors, and the install walk ends the job against it. JSON columns are stored as `LONGTEXT` with a check constraint. |
| PostgreSQL | 14+ | Tested: the full suite runs against PostgreSQL 14 and 17 in CI, on both Laravel majors, and the install walk ends the job against it. |
| SQL Server | 2022+ | Tested: the full suite runs against SQL Server 2022 in CI, on both Laravel majors, and the install walk ends the job against it. Laravel's `sqlsrv` driver; needs `pdo_sqlsrv` and Microsoft's ODBC driver. On SQL Server the direct `laraflow_step_attempts.workflow_execution_id` foreign key does not cascade — the engine refuses a second cascade path — and deletion flows through `laraflow_step_executions` instead; retention's own deletes never relied on cascades anywhere. |

Laravel 13 requires PHP 8.3, so that combination's floor is 8.3 rather than 8.2; the matrix excludes the pair that cannot exist.

**One dependency beyond `illuminate/*`.** Schedules read cron expressions through `dragonmantank/cron-expression` (`^3.4`), which `illuminate/console` only *suggests* — Laravel's own scheduler needs it, so `laravel/framework` requires it and every Laravel application already has it installed, but a package that relied on that would be relying on a dependency it never declared. It is required directly, at the constraint `laravel/framework` 12 and 13 share, so the `prefer-lowest` cell exercises its floor like every other.

**Suggested, not required.** The inspection dashboard needs `illuminate/routing`, `illuminate/view`, and `illuminate/session`, and the trigger endpoints need `illuminate/routing` alone; `composer.json` lists all three under `suggest`. Every `laravel/framework` application already has them, so nothing changes for an ordinary install, and a container that only runs workflows keeps its footprint. Mounting the dashboard without them refuses by name rather than failing on the first request. They carry the same version constraints as the rest of the `illuminate/*` set.

**Laravel 11 and below, and PHP 8.1 and below, are not supported and will not be added.** This follows the drop policy below rather than taste:

- Laravel 10 (February 2023) and Laravel 11 (March 2024) are both past the end of their bug-fix windows, and Laravel 10 requires only PHP 8.1. Supporting a major that upstream no longer fixes would mean carrying compatibility shims for a platform whose own bugs will never be repaired.
- PHP 8.1 reached security end-of-life in December 2025. Laraflow's option and policy objects (`HttpOptions`, `TransportPolicy`, `RateLimitPolicy`, `Credentials`, `Wait`) are `final readonly class`, which is PHP 8.2 syntax; dropping to 8.1 would mean giving up compile-time immutability across the durable boundaries in exchange for an unsupported runtime.

If an application is pinned to Laravel 10 or 11, the right answer is to upgrade the application — not to widen this package.

The whole test suite runs against any of these engines by setting `LARAFLOW_TEST_DB_DRIVER` and the connection variables documented in [CONTRIBUTING.md](../CONTRIBUTING.md) — that is the mechanism the Milestone 6 CI matrix uses to turn "expected to work" into "tested".

**MongoDB is not a supported persistence backend.** It is not one of Laravel's native database drivers, and Laraflow's durability contract is built on what relational engines provide: a migrated schema, foreign keys, compare-and-set `UPDATE ... WHERE` claiming, and indexed keyset pagination. The third-party `mongodb/laravel-mongodb` Eloquent layer does not change that. A document-store persistence driver is post-1.0 work ("additional persistence drivers only when the abstractions have real consumers"), designed against the `ExecutionRepository` contract rather than by pointing the existing migrations at Mongo.

### What "supported" means before 1.0

- **Tested** means the test suite runs against it in the repository's own development or CI workflow.
- **Expected to work** means the package uses only portable Laravel abstractions (schema builder, Eloquent, JSON casts) for that platform, but no automated run proves it. Bug reports for these platforms are welcome and will be treated as defects, not feature requests.

Milestone 6 promoted every entry on this table to **tested**: the CI matrix runs the whole suite against each engine on every push.

## Database requirements and schema review

Reviewed in full for Milestone 6 before the schema freeze; every decision below is deliberate and most are pinned by `tests/Feature/Database/MigrationTest.php`.

### Types and sizes

- A `json` column type. On MySQL/PostgreSQL this maps to native JSON, on MariaDB to `LONGTEXT` with a check constraint, on SQLite to `TEXT`, on SQL Server to `nvarchar(max)`. The repository JSON-encodes these values itself when writing through the query builder, because not every grammar does.
- String columns are 255 characters (`claim_token` is 64), and the indexed ones — `workflow`, `status`, `correlation_id`, `tenant_id` — are what the key-size arithmetic has to cover. That fits every engine's index key limit with room to spare — the widest composite, `(workflow, status)`, is 2040 bytes on utf8mb4 against InnoDB's 3072-byte ceiling and 1020 bytes against SQL Server's 1700 — and matches `ExecutionOptions::MAX_LENGTH`, which validates application-supplied identifiers at the boundary.
- `laraflow_workflow_executions.version` is an unsigned counter bumped by every repository write. It is not optimistic locking — nothing reads it. It exists so a compare-and-set `UPDATE` that rewrites identical values is still counted on engines that report rows *changed* rather than rows *matched* (MySQL, MariaDB).
- `laraflow_workflow_executions.fruitless_claims` (added by the first post-freeze migration, Milestone 7) counts consecutive claims that ended before any step-row write or orderly release; `claim()` increments it, the first step write and every `release()` zero it. Recovery's abandonment bound reads it alongside step attempts. Unindexed: it is read per candidate, never scanned.
- `laraflow_step_executions.wait_signal` (second post-freeze migration, Milestone 7) names the signal a waiting step is parked on; null for timed waits and every non-waiting step. Unindexed: signal delivery matches it inside a query already bounded by the execution id.
- `laraflow_workflow_executions.parent_execution_id` / `parent_step_execution_id` (third post-freeze migration, Milestone 7) link a sub-workflow to the parent step that spawned it. Plain columns, **not** foreign keys, so retention may prune a long-finished parent while the child's audit record lives on — the documented trade-off in [retention.md](retention.md#sub-workflows). `parent_execution_id` has been indexed since Milestone 7; `parent_step_execution_id` since Milestone 9's first migration (the eighth post-freeze), for the join's one-query progress read.
- `laraflow_step_executions.compensates_step_execution_id` (ninth post-freeze migration, Milestone 9) links a compensation row to the step row it undoes; null on every planned step, which is how the plan is told from what was appended after it. A plain column, **not** a foreign key: a self-reference inside the same table is a second cascade path from the executions row, which SQL Server refuses — the same constraint `laraflow_step_attempts.workflow_execution_id` meets. The rows share a parent and are deleted by it, so nothing is orphaned. Indexed: the compensator reads by it on every claim of a compensating execution.
- `laraflow_workflow_executions.plan_version` (sixth post-freeze migration, Milestone 8) records which version of its workflow's plan an execution is running, so a declared plan migration can carry it forward after a deploy changes the step list. Default 1, so a row predating the column reads as version 1 — which is what it is. Unindexed: read per claimed execution, never scanned. See [workflow-versioning.md](workflow-versioning.md).
- `laraflow_idempotency_keys` (seventh post-freeze migration, Milestone 8) is the fourth table: one row per piece of work a step began under `remember()`, keyed `(workflow_execution_id, step_position, key)` and unique on that triple — the constraint is what stops two live workers both performing a side effect. The marker row is inserted before the work and settled after, so a row left `pending` is evidence the work began and no evidence it finished. `value` is `json`, cleared by the scrub tier; the foreign key cascades, and the table is deleted with its execution through the orphan-safe path regardless. See [idempotency.md](idempotency.md).
- `laraflow_schedules` and `laraflow_schedule_ticks` (tenth and eleventh post-freeze migrations, Milestone 10) are the fifth and sixth tables: one row per registered schedule, synchronised from the workflow class it caches, and one insert-only row per tick claimed. The ticks table's unique `(schedule_id, tick_at)` is the once-per-tick guarantee — every scheduler computes the same instant and exactly one insert wins — the same shape as the idempotency store's unique triple. `workflow_execution_id` on a tick is a plain column, **not** a foreign key, for the trade-off the sub-workflow links already took: tick history outlives the executions retention deletes. Ticks carry no `updated_at` and no `version` counter, deliberately: nothing ever writes a tick twice. See [schedules.md](schedules.md).
- `laraflow_workflow_executions.deferred_until` / `deferrals` (twelfth post-freeze migration, Milestone 10) record a claim a tenant ceiling turned away — when delivery is next tried, and how many times it has been — without touching the execution's status, so the record says "waiting for a tenant slot" rather than looking like a lost dispatch; the first claim that lands clears both. The same migration adds `(tenant_id, status, claimed_at)`, the index behind the one count every bounded claim makes, and `deferred_until`, behind the attention scan's "what is deferred right now". Both index a populated table: not online on PostgreSQL inside a migration transaction nor on SQL Server outside Enterprise ([upgrading.md](upgrading.md)). See [tenancy.md](tenancy.md).
- Three post-freeze migrations add only indexes: `(payloads_scrubbed_at, status, finished_at)` (fourth, Milestone 7) for the scrub tier's scan, `(status, created_at)` (fifth, Milestone 8) for the opt-in pending scan — each closing a scan whose cost had grown with history rather than with its backlog — and `parent_step_execution_id` (eighth, Milestone 9), so a fan-out's join counts its members in one indexed query rather than reading them. All three appear in the table below.

### Indexes, and the ones deliberately absent

Every query the package issues is served by an index:

| Index | Serves |
|-------|--------|
| `(status, claimed_at)` | The stale-claim recovery scan; bare `status` filters via its prefix. |
| `(status, next_retry_at)` | The due-retry and due-wait scans — a wait's resume instant, deadline, signal due-time, and sub-workflow heartbeat all live in `next_retry_at`. |
| `(status, created_at)` | The opt-in never-dispatched recovery scan: `pending` rows created before a cutoff. |
| `(status, finished_at)` | Retention candidate scans: the delete tiers, and attempt pruning. |
| `(payloads_scrubbed_at, status, finished_at)` | The scrub tier's scan, which leads with `IS NULL` so already-scrubbed rows are skipped by the index rather than walked. |
| `(workflow, status)` | Listing a workflow's executions, with or without a status. |
| `correlation_id`, `tenant_id`, `created_at` | Listing filters; keyset pagination orders by the primary key. |
| `parent_execution_id` | A sub-workflow's children: the parent filter, the cancellation cascade, the same-child lookup on a parent-step retry. |
| `parent_step_execution_id` | `childProgress()`: a fan-out join's count of its members by status, on every heartbeat, and the members-of-this-step lookup on a fan-out step's retry. |
| `laraflow_step_executions (compensates_step_execution_id)` | The compensation pass: which steps already have an undo row, read on every claim of a compensating execution. |
| `laraflow_step_executions (workflow_execution_id, position)` unique | "Steps of this execution" reads, `stepProgress()`'s grouping, the plan's one-row-per-position invariant — and the foreign key itself, via the leftmost prefix. |
| `laraflow_step_attempts (step_execution_id, attempt)` unique | One row per logical attempt; a duplicate delivery cannot double-count. |
| `laraflow_step_attempts (workflow_execution_id)` | The prune scan and `attemptHistory()`. Not redundant: PostgreSQL, SQLite, and SQL Server create no index for a foreign key on their own; MySQL and MariaDB reuse this one. |
| `(tenant_id, status, claimed_at)` | The tenant concurrency ceiling: this tenant's executions holding a worker right now — running or compensating with a fresh claim — counted on every bounded claim. |
| `deferred_until` | The attention scan's count of executions a tenant ceiling is holding past now, scoped like every other number on the summary. |
| `laraflow_schedules (workflow)` unique | The registry's only lookup: a schedule row by the class that declares it, and the one-schedule-per-workflow invariant. |
| `laraflow_schedule_ticks (schedule_id, tick_at)` unique | The once-per-tick claim — one insert wins — and, via its prefix, the foreign key and the "latest tick of this schedule" read behind overlap suppression. |
| `laraflow_schedule_ticks (workflow_execution_id)` | The origin lookup (which tick started this execution), the overlap check, and the scheduler's re-dispatch scan for pending executions its own ticks created. |
| `laraflow_schedule_ticks (created_at)` | Retention's prune-ticks scan, when the bound ships; the index ships with the table so enabling it needs no second migration. |
| `laraflow_idempotency_keys (workflow_execution_id, step_position, key)` unique | `remember()`'s marker insert — exactly one of two concurrent attempts succeeds — and the lookup of the record it found; and, by its leading column, every read by execution: the key count behind the cap, `laraflow:show --attempts`, retention's scrub and orphan deletes. The table's only index, so the foreign key needs none of its own. |

Deliberately absent: a single-column `status` index (four composites already lead with `status`; another index on the same prefix only taxes writes) and any index on `laraflow_step_executions.status` (no query filters step rows by status alone).

### Foreign keys and cascades

- `laraflow_step_executions.workflow_execution_id` and `laraflow_step_attempts.step_execution_id` cascade on delete everywhere.
- `laraflow_step_attempts.workflow_execution_id` cascades everywhere **except SQL Server**, which refuses a second cascade path from the same root; there, deletion flows through the step row, which covers every attempt because `step_execution_id` is NOT NULL.
- Nothing in the package depends on cascades firing: retention deletes children before parents explicitly and proves it with SQLite's enforcement switched off. The cascades exist so the schema's guarantees hold for every *other* writer. SQLite enforces them only when `foreign_key_constraints` is enabled on the connection.

### Growth and concurrency

- `laraflow_step_attempts` is append-only and is the table that grows under retry policies. Retention is off by default; `laraflow:prune` bounds it once `laraflow.retention.*` is configured (see [retention.md](retention.md)). `laraflow.execution.max_payload_bytes` bounds row width once set.
- Claiming uses row-level compare-and-set `UPDATE ... WHERE` — no advisory locks, no `SELECT ... FOR UPDATE` — so it works on every engine above, including SQLite's single-writer model, and was exercised against all five in CI.

## Provider APIs

Connectors talk to services Laraflow does not control, so a second compatibility surface exists: the provider's API. The expectations:

- **A connector declares the version it was written against** (`HttpConnector::apiVersion()`), sends it where the provider selects versions by header (`apiVersionHeader()`), and `laraflow:connectors` displays it. The bundled GitHub connector pins `2022-11-28`. A provider without versions — Slack — declares none and is shown as *unversioned*; its methods are added and deprecated individually, and the connector tracks "current".
- **A connection may pin another version** with `api_version`, to move onto a new one early or stay on an old one while an application catches up. The pin is per account, so one connection can be tested against a new version while the rest stay put.
- **"Tested" means tested against recorded shapes, not live.** Connector tests fake HTTP and run without credentials or network, so they prove the connector handles the responses the provider documented for that version. They cannot prove the provider still sends them. A provider changing its API under a pinned version is a defect report Laraflow wants, fixed in a patch release; a connector moving to a newer provider version is a minor release, noted in the changelog with what changed.
- **Pre-1.0, bundled connectors are experimental**, and a provider's own deprecation may force a change that would otherwise be breaking. Those are announced in the changelog like any other breaking change.

## Dropping support

- A PHP or Laravel version is dropped only in a **minor** release before 1.0, or a **major** release after 1.0, and is announced in the changelog.
- PHP versions are dropped when they reach end of security support at php.net.
- Laravel versions are dropped when they leave Laravel's bug-fix window.
- Database engine versions are dropped when their vendor ends general support (for SQL Server, mainstream support). 0.6.0 applied this to MySQL 8.0, MariaDB 10.6, PostgreSQL 13, and SQL Server 2019; the floors above are the result.

## Versioning

Laraflow follows [Semantic Versioning 2.0](https://semver.org). What counts as the public API for versioning purposes is exactly what [public-api.md](public-api.md) marks **Public**: experimental classes may change in any minor, and internal ones in any release.

- **Before 1.0:** `0.x` minor versions may contain breaking changes. Each is documented in `CHANGELOG.md` under **Breaking** with upgrade notes. Patch versions are backwards compatible.
- **After 1.0:** breaking changes only in major versions, with a deprecation period of at least one minor version where practical.

### How a deprecation works

A deprecation is visible in three places before anything is removed, so no removal is a surprise:

1. **The docblock** gains `@deprecated`, naming the replacement — this is what an IDE and static analysis surface at the call site.
2. **The changelog** lists it under a *Deprecated* heading in the release that introduces it, with the replacement and the planned removal version.
3. **The removal** happens no sooner than the next major (post-1.0) or the next minor (pre-1.0), and is listed under *Removed*.

Deprecated code keeps working and keeps its tests until it is removed. Laraflow does not emit runtime deprecation notices for its own API — the suite runs with `failOnDeprecation`, and a library that warns on every call punishes the applications that upgrade most carefully. Configuration keys follow the same path: a renamed key keeps its old name working for the deprecation window, with the old name noted in `config/laraflow.php`.

The database schema is **frozen**: the shipped migrations are immutable, and any later change arrives as a new dated migration that an installed application applies with `php artisan migrate`. Upgrading, rolling back, and recovering interrupted work are specified in [upgrading.md](upgrading.md). A schema change is still a notable change and is called out in `CHANGELOG.md` with its operational impact.

## Support channels

- **Bugs and feature requests:** [GitHub issues](https://github.com/jhaskell13/laraflow/issues).
- **Security vulnerabilities:** see [SECURITY.md](../SECURITY.md). Do not open a public issue.
- **Questions:** GitHub issues, tagged as a question.

There is no commercial support and no response-time guarantee. Which releases receive fixes — the latest minor, and the previous one for a stated window — is defined once, in [SECURITY.md](../SECURITY.md#supported-versions).
