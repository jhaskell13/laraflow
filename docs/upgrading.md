# Upgrading, rolling back, and recovering

This document is the operational contract for moving an application between Laraflow versions. It covers what the schema freeze guarantees, the procedure for an ordinary upgrade, how to roll one back, and how to recover work that was in flight while you did it.

The guarantees here start at the schema freeze (Milestone 6). Before it, migrations were edited in place and the only supported answer to a schema change was to reinstall the tables. That is over.

## What the freeze guarantees

**The shipped migrations are immutable.** The three dated migrations that create `laraflow_workflow_executions`, `laraflow_step_executions`, and `laraflow_step_attempts` will not change again. Any later schema change arrives as a *new* dated migration.

Concretely, for an installed application:

- `php artisan migrate` is always enough. You will never be told to drop and recreate Laraflow's tables to pick up a change.
- Running `migrate` when nothing changed is a no-op, not an error.
- A schema change can be rolled back with `migrate:rollback` without losing the install or the rows in it.

This is enforced, not promised: `tests/Feature/Database/SchemaFreezeTest.php` fails if a shipped migration's resulting schema changes at all, and `tests/Feature/Database/UpgradePathTest.php` applies a new migration to an already-populated database and asserts the data survives both the upgrade and its rollback.

Every schema change is called out in `CHANGELOG.md` under the release that introduced it, with its operational impact.

## Upgrading

Laraflow is a library: its version moves when you ask Composer to move it.

```bash
composer update laraflow/laraflow
php artisan migrate
```

Before a **minor or major** bump, read the changelog entries between your version and the new one. Look for three things: schema changes (they tell you what `migrate` will do), behaviour marked **Breaking**, and anything listed under *Deprecations*.

### Upgrading with a schema change, without downtime

Laraflow's own migrations are written to be additive — new nullable columns, new indexes, new tables — so the usual sequence is safe while workers are running:

1. **Deploy the new code to a worker-free host first**, or put the new code in place without restarting workers. Old workers keep running the old code against the old schema.
2. **Run `php artisan migrate`.** Additive changes do not disturb rows that the running workers hold claims on.
3. **Restart workers** so they pick up the new code. Executions claimed by a worker that stops mid-run are not lost; see [Recovering work](#recovering-work-interrupted-by-an-upgrade).

If a release ever needs a migration that is *not* safe to run against live workers, its changelog entry will say so explicitly and give the sequence. Drain the queue first in that case: stop dispatching, let `php artisan queue:work --stop-when-empty` finish, then migrate.

### Checking before you commit to it

On a copy of production data:

```bash
php artisan migrate --pretend     # the SQL, without running it
php artisan migrate               # on the copy
php artisan laraflow:summary      # the executions are all still there
php artisan laraflow:connectors --check
```

`laraflow:connectors --check` resolves every connector and exits non-zero if one cannot be built, so it belongs in the deploy pipeline whether or not the schema moved.

## Rolling back

### Rolling back a schema change

`migrate:rollback` undoes the last batch. Because Laraflow's migrations are additive, this drops what the upgrade added and leaves the install and its rows intact:

```bash
php artisan migrate:rollback --step=1
composer require laraflow/laraflow:<previous-version>
```

Roll the code back **with or after** the schema, not before: older code does not know about a newer column, but newer code may require one.

A rollback that crosses a release which *removed* something is the case to be careful with — a `down()` can recreate a column but cannot recreate the data that was in it. Changelog entries for destructive migrations say so.

### Rolling back the application only

If the schema did not move between the two versions — the common case — rolling back is just Composer:

```bash
composer require laraflow/laraflow:<previous-version>
```

Nothing else is needed. Executions persisted by the newer code remain readable, because the schema they were written against is the same one.

## Recovering work interrupted by an upgrade

Restarting workers mid-execution is ordinary, expected, and already covered by the durability contract: a worker that stops holds a claim that ages out, and the execution resumes from its last checkpoint. The full semantics are in [execution-lifecycle.md](execution-lifecycle.md) and [queue-and-recovery.md](queue-and-recovery.md); what matters for an upgrade:

```bash
# What is in flight, and what is stuck
php artisan laraflow:summary

# Re-dispatch work whose worker disappeared, under explicit bounds
php artisan laraflow:recover --dry-run
php artisan laraflow:recover
```

`laraflow:recover` only touches *interrupted* work — executions claimed by a worker that is gone. It does not retry genuinely failed executions; those answer to their step's retry policy, or to `laraflow:execute <id>` if you want to re-run one deliberately.

Two points that matter during a deploy:

- **Steps may run more than once.** Laraflow is at-least-once at recovery boundaries. If a step has an external side effect, it needs the idempotency hooks described in [execution-lifecycle.md](execution-lifecycle.md) — an upgrade is exactly when that gets exercised.
- **A stale claim is not a failure.** Give a restarted worker its claim timeout (`laraflow.execution.stale_after`) before deciding something is stuck.

## If an upgrade goes wrong

1. **Stop dispatching** new executions. Leave the workers alone for now — in-flight work is checkpointed.
2. **Look before acting:** `php artisan laraflow:summary`, then `laraflow:show <id>` on anything that looks wrong. Both read the public query contract, so neither depends on schema internals.
3. **Roll back** the schema and the code, in that order, per [Rolling back](#rolling-back).
4. **Recover** what was interrupted: `php artisan laraflow:recover --dry-run` first, then without it.
5. Nothing above deletes execution history. Retention is the only thing that removes rows, it is off by default, and it never scrubs or deletes a `failed` execution under the general bound — see [retention.md](retention.md).

## Version support

Which versions receive fixes, how long a deprecation lasts before removal, and the policy for dropping PHP, Laravel, or database versions are in [compatibility.md](compatibility.md). Security fixes and their support window are in [SECURITY.md](../SECURITY.md).
