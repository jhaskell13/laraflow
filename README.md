# Laraflow

**A framework for building integrations and durable workflows in Laravel.**

Applications often begin integrating external services with a few HTTP calls and queued jobs. As an integration grows, application code takes on more responsibility: coordinating multiple steps, carrying data between them, handling failures, keeping track of what ran, and figuring out where to resume when something goes wrong.

Laraflow gives that work a home. Workflows are ordered, named steps written as plain PHP classes. Connectors give external services a consistent place for authentication, requests, responses, and errors. A runner executes workflows and records every execution and step in your database so progress can be inspected and, when safe, continued.

> **Status: `1.0`.** Linear workflows — an ordered list of steps, made durable, resumable, and observable — where a step may be conditional, wait for a delay or a signal, run a child workflow, fan out and join, or undo itself when the workflow fails. Cron schedules that fire exactly once per tick, signed HTTP triggers, per-tenant ceilings, a console that speaks JSON, and an opt-in dashboard.
>
> **What you can rely on.** The [public API](docs/public-api.md) is frozen: it breaks only in a major release, and a committed signature baseline fails the build if any public class moves. The database schema is frozen too, and `php artisan migrate` is always enough. `php artisan laraflow:upgrade` tells an application what it must change before an upgrade. The suite runs on every supported PHP, both Laravel majors, and all five database engines; a [soak suite](docs/soak.md) runs them under load with workers killed and containers stopped; a security review against a published [threat model](docs/threat-model.md) closed its findings.
>
> **What 1.0 does not claim.** That it has production mileage — it has none yet, and [limitations.md](docs/limitations.md) is the honest list of what it does not do and where it is exact only in the steady state.

## Requirements

| Dependency | Supported |
|------------|-----------|
| PHP        | 8.3, 8.4, 8.5 |
| Laravel    | 12.x, 13.x (Laravel 13 requires PHP 8.3+) |
| Database   | SQLite, MySQL 8.4+, PostgreSQL 14+, MariaDB 10.11+, SQL Server 2022+ — all five run the full suite in CI |

See [docs/compatibility.md](docs/compatibility.md) for the full policy, including what "supported" means and when a version is dropped.

## Installation

```bash
composer require jhaskell13/laraflow
```

Laravel's package auto-discovery registers `Laraflow\Core\LaraflowServiceProvider`. No manual provider registration is needed. Then:

```bash
php artisan laraflow:install
```

That publishes `config/laraflow.php`, runs the migrations, and prints what to do next. It does nothing `vendor:publish` and `migrate` would not, so [Getting started](docs/getting-started.md#database-tables) shows the same thing by hand.

From there, the whole first workflow is three commands and one edit:

```bash
php artisan laraflow:make-workflow FulfilOrder          # app/Workflows/FulfilOrder.php
php artisan laraflow:make-step ReserveInventory         # app/Workflows/Steps/ReserveInventory.php
# name the step in the workflow's steps() list, then:
php artisan laraflow:start FulfilOrder --input='{"order_id": 42}'
php artisan laraflow:show 1
```

The whole first run, with its failure and recovery, is in [Getting started](docs/getting-started.md).

## Documentation

The documentation lives in [`docs/`](docs/) and is published as a site from it.

| Start here | |
|---|---|
| [Getting started](docs/getting-started.md) | A first workflow: write it, run it, watch it fail, recover it |
| [Tutorial: the playground](docs/playground.md) | Every recovery path, hands-on, without creating an application |
| [Configuration](docs/configuration.md) | What each section of `config/laraflow.php` controls |
| [Using Laraflow](docs/guide.md) | Retries, waits, sub-workflows, fan-out, compensation, versioning, queues, schedules, triggers, tenants, inspection, retention, the dashboard |
| [Your first connector](docs/first-connector.md) | Calling external services: the bundled GitHub and Slack connectors, and your own |
| [Limitations and design principles](docs/limitations.md) | What Laraflow deliberately does not do yet, and why |

| The contracts | |
|---|---|
| [Execution lifecycle](docs/execution-lifecycle.md) · [Retries and failure](docs/retry-and-failure-policy.md) · [Advanced workflows](docs/advanced-workflows.md) · [Idempotency](docs/idempotency.md) · [Versioning](docs/workflow-versioning.md) | How a workflow runs and recovers |
| [Queues and recovery](docs/queue-and-recovery.md) · [Inspection](docs/inspection-and-observability.md) · [Metrics](docs/metrics.md) · [Tracing](docs/tracing.md) · [Dashboard](docs/dashboard.md) · [Retention](docs/retention.md) | Running it in production |
| [Schedules](docs/schedules.md) · [Triggers](docs/triggers.md) · [Tenancy](docs/tenancy.md) · [Connectors](docs/connectors.md) | Starting and feeding workflows |
| [Public API](docs/public-api.md) · [Compatibility](docs/compatibility.md) · [Upgrading](docs/upgrading.md) · [Releases and support](docs/releases.md) · [Decisions](docs/decisions/README.md) | What is promised, and why |

## Compatibility and support

Laraflow follows [semantic versioning](https://semver.org). From 1.0, breaking changes to anything [public-api.md](docs/public-api.md) marks **Public** wait for a major release, after a deprecation carried through at least one minor; every change is in [CHANGELOG.md](CHANGELOG.md). The supported PHP, Laravel, and database versions, and the policy for dropping them, are in [docs/compatibility.md](docs/compatibility.md). Which classes are public, experimental, or internal is in [docs/public-api.md](docs/public-api.md).

The database schema is frozen: the shipped migrations never change, later schema changes arrive as new dated migrations, and `php artisan migrate` is always enough. Upgrading, rolling back, and recovering work interrupted by a deploy are in [docs/upgrading.md](docs/upgrading.md).

Security issues should be reported privately as described in [SECURITY.md](SECURITY.md).

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, testing, and the kind of changes most likely to be accepted at this stage, and [MAINTAINERS.md](MAINTAINERS.md) for who reviews them. The development sequence is in [ROADMAP.md](ROADMAP.md).

## License

Laraflow is open-sourced software licensed under the [MIT license](LICENSE).
