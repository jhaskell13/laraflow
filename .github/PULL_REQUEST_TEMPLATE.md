## What this changes

<!-- The behaviour, in a sentence or two. Link the issue or roadmap item. -->

## Why

<!-- The problem it solves, and the alternative you did not take. -->

## Contract impact

- [ ] No public API, schema, or documented behaviour changes
- [ ] Public API changed — `docs/public-api.md` and `CHANGELOG.md` updated, marked **Breaking** if it is
- [ ] Schema changed — a *new* dated migration; `SchemaFreezeTest` and `UpgradePathTest` updated in this PR
- [ ] A contract document changed — its enforcing test changed with it

## Checks

- [ ] `composer check` passes locally (lint, static analysis, tests)
- [ ] New behaviour has a test that fails without the change
- [ ] Engine-specific behaviour considered (MySQL JSON key order and changed-row counts, pdo_sqlsrv strings, PostgreSQL NUL bytes, SQLite single writer)
