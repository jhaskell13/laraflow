# Compatibility and support policy

This policy states which platform versions Laraflow supports, what "supported" means at this stage of the project, and how support is dropped.

## Supported versions

| Component | Versions | Status |
|-----------|----------|--------|
| PHP | 8.2, 8.3, 8.4 | 8.3 is exercised in the current test runs. Others are declared via Composer and expected to work; CI coverage arrives in Milestone 6. |
| Laravel | 12.x | Declared via `illuminate/*` `^12.0`; tested with Orchestra Testbench 10. |
| SQLite | 3.x | Used by the test suite (in-memory). |
| MySQL | 8.0+ | Expected to work; not yet in CI. |
| MariaDB | 10.6+ | Expected to work; not yet in CI. JSON columns are stored as `LONGTEXT` with a check constraint. |
| PostgreSQL | 13+ | Expected to work; not yet in CI. |

Laravel versions below 12 and PHP below 8.2 are **not** supported and will not be added.

### What "supported" means before 1.0

- **Tested** means the test suite runs against it in the repository's own development or CI workflow.
- **Expected to work** means the package uses only portable Laravel abstractions (schema builder, Eloquent, JSON casts) for that platform, but no automated run proves it. Bug reports for these platforms are welcome and will be treated as defects, not feature requests.

Milestone 6 of the roadmap promotes every "expected to work" entry to "tested" by expanding the CI matrix, or removes it from this table.

## Database requirements

- A `json` column type. On MySQL/MariaDB/PostgreSQL this maps to native JSON; on SQLite to `TEXT`.
- Foreign keys with cascading deletes (`laraflow_step_executions.workflow_execution_id`).
- Milestone 1 adds row-level compare-and-set updates for execution claiming. These use standard `UPDATE ... WHERE` semantics and do not require advisory locks or `SELECT ... FOR UPDATE`, so they work on every engine above, including SQLite's single-writer model.

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
