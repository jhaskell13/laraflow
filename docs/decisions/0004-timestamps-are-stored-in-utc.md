# 0004 — Every stored timestamp is UTC

**Status:** Accepted (0.6.0). Supersedes the earlier convention of storing the application timezone's wall clock.

## Context

Eloquent stores the wall clock of whatever timezone the application runs in. In a timezone with daylight saving time, the repeated autumn hour makes two different instants store as the same value: a schedule tick was lost and reported as "taken", and due-time comparisons were wrong for that hour. MySQL's `timestamp` type also stops at 2038.

## Decision

Every Laraflow timestamp is written and compared in UTC, converted at the model and query-builder boundary, regardless of `app.timezone`. MySQL and MariaDB columns are `datetime`. Values handed to application code are the same instants as before and still render in the application's timezone ([compatibility.md](../compatibility.md), [upgrading.md](../upgrading.md)).

## Rejected

- **Requiring applications to run in UTC.** Correct, but a requirement a package cannot enforce and most users would discover in production.
- **Storing timezone-aware types.** Not portable across the supported engines.

## Consequences

Non-UTC applications ran a one-time conversion migration with workers stopped. Anyone reading Laraflow's tables directly must treat the columns as UTC.
