# Compatibility and support policy

This policy states which platform versions Laraflow supports, what "supported" means at this stage of the project, and how support is dropped.

## Supported versions

| Component | Versions | Status |
|-----------|----------|--------|
| PHP | 8.2, 8.3, 8.4, 8.5 | Every version runs in the GitHub Actions matrix (`.github/workflows/ci.yml`). |
| Laravel | 12.x, 13.x | Declared via `illuminate/*` `^12.0|^13.0`; tested with Orchestra Testbench 10 and 11. CI runs both majors and a `prefer-lowest` leg, so the floor of every constraint is proven, not assumed. |
| SQLite | 3.x | Tested: the suite's default (in-memory). |
| MySQL | 8.0+ | Tested: the full suite runs against MySQL 8.0 in CI. |
| MariaDB | 10.6+ | Tested: the full suite runs against MariaDB 10.6 in CI. JSON columns are stored as `LONGTEXT` with a check constraint. |
| PostgreSQL | 13+ | Tested: the full suite runs against PostgreSQL 13 in CI. |
| SQL Server | 2019+ | Tested: the full suite runs against SQL Server 2019 in CI. Laravel's `sqlsrv` driver; needs `pdo_sqlsrv` and Microsoft's ODBC driver. On SQL Server the direct `laraflow_step_attempts.workflow_execution_id` foreign key does not cascade — the engine refuses a second cascade path — and deletion flows through `laraflow_step_executions` instead; retention's own deletes never relied on cascades anywhere. |

Laravel 13 requires PHP 8.3, so that combination's floor is 8.3 rather than 8.2; the matrix excludes the pair that cannot exist.

**Laravel 11 and below, and PHP 8.1 and below, are not supported and will not be added.** This follows the drop policy below rather than taste:

- Laravel 10 (February 2023) and Laravel 11 (March 2024) are both past the end of their bug-fix windows, and Laravel 10 requires only PHP 8.1. Supporting a major that upstream no longer fixes would mean carrying compatibility shims for a platform whose own bugs will never be repaired.
- PHP 8.1 reached security end-of-life in December 2025. Laraflow's value objects are `final readonly class`, which is PHP 8.2 syntax; dropping to 8.1 would mean giving up compile-time immutability across the durable boundaries in exchange for an unsupported runtime.

If an application is pinned to Laravel 10 or 11, the right answer is to upgrade the application — not to widen this package.

The whole test suite runs against any of these engines by setting `LARAFLOW_TEST_DB_DRIVER` and the connection variables documented in [CONTRIBUTING.md](../CONTRIBUTING.md) — that is the mechanism the Milestone 6 CI matrix uses to turn "expected to work" into "tested".

**MongoDB is not a supported persistence backend.** It is not one of Laravel's native database drivers, and Laraflow's durability contract is built on what relational engines provide: a migrated schema, foreign keys, compare-and-set `UPDATE ... WHERE` claiming, and indexed keyset pagination. The third-party `mongodb/laravel-mongodb` Eloquent layer does not change that. A document-store persistence driver would be a Milestone 7 item ("additional persistence drivers only when the abstractions have real consumers"), designed against the `ExecutionRepository` contract rather than by pointing the existing migrations at Mongo.

### What "supported" means before 1.0

- **Tested** means the test suite runs against it in the repository's own development or CI workflow.
- **Expected to work** means the package uses only portable Laravel abstractions (schema builder, Eloquent, JSON casts) for that platform, but no automated run proves it. Bug reports for these platforms are welcome and will be treated as defects, not feature requests.

Milestone 6 promoted every entry on this table to **tested**: the CI matrix runs the whole suite against each engine on every push.

## Database requirements

- A `json` column type. On MySQL/MariaDB/PostgreSQL this maps to native JSON; on SQLite to `TEXT`.
- Foreign keys with cascading deletes (`laraflow_step_executions.workflow_execution_id`, and both foreign keys on `laraflow_step_attempts`). SQLite enforces these only when `foreign_key_constraints` is enabled on the connection.
- `laraflow_step_attempts` holds one append-only row per finished step attempt and is the table that grows under retry policies. Retention is off by default; `laraflow:prune` bounds it once `laraflow.retention.*` is configured (see [retention.md](retention.md)). Deletion does not rely on the database enforcing the declared cascades — it works with SQLite's foreign-key enforcement off — but enabling enforcement is still recommended so the schema's own guarantees hold for every other writer.
- Milestone 4 adds indexes on `correlation_id`, `tenant_id`, `created_at`, `(workflow, status)`, and `(status, finished_at)` so that every listing filter and every retention scan is served from an index. The `GROUP BY` behind `stepProgress()` uses the existing foreign-key index on `laraflow_step_executions`.
- Milestone 1 adds row-level compare-and-set updates for execution claiming. These use standard `UPDATE ... WHERE` semantics and do not require advisory locks or `SELECT ... FOR UPDATE`, so they work on every engine above, including SQLite's single-writer model.

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
- Database engine versions are dropped when their vendor ends general support.

## Versioning

Laraflow follows [Semantic Versioning 2.0](https://semver.org).

- **Before 1.0:** `0.x` minor versions may contain breaking changes. Each is documented in `CHANGELOG.md` with upgrade notes. Patch versions are backwards compatible.
- **After 1.0:** breaking changes only in major versions, with a deprecation period of at least one minor version where practical.

Database schema changes are treated as breaking until the schema is frozen (see roadmap Milestone 6). Until then, migrations may be edited in place and there is no guaranteed upgrade path between development snapshots. Reinstall the tables when pulling a new snapshot.

## Support channels

- **Bugs and feature requests:** [GitHub issues](https://github.com/jhaskell13/laraflow/issues).
- **Security vulnerabilities:** see [SECURITY.md](../SECURITY.md). Do not open a public issue.
- **Questions:** GitHub issues, tagged as a question.

There is no commercial support and no response-time guarantee. Only the latest development snapshot (and, once tagged, the latest minor release) receives fixes.
