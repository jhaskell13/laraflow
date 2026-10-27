# Contributing to Laraflow

Thanks for your interest. Laraflow is early-stage, so the most valuable contributions right now are focused ones: bug reports with reproductions, test coverage for recovery edge cases, and changes that advance the current milestone in [ROADMAP.md](ROADMAP.md).

## Before you start

- **Check the roadmap.** Work is ordered by dependency. A feature from a later milestone (branching, sub-workflows, new connectors, a dashboard) is unlikely to be merged before the milestone in progress is complete, however good the code is. Open an issue first to discuss anything beyond the current milestone.
- **Open an issue for anything non-trivial.** Bug fixes with tests can go straight to a pull request. Design changes, new public API, and schema changes should be discussed first.
- **Read [docs/public-api.md](docs/public-api.md).** Changes to public classes need a changelog entry; changes to internal classes do not.

## Development setup

```bash
git clone https://github.com/jhaskell13/laraflow.git
cd laraflow
composer install
composer check   # code style + static analysis + tests
```

CI (`.github/workflows/ci.yml`) splits the work: a single quality job runs `composer validate --strict`, `composer audit`, lint, and static analysis; the test matrix covers PHP 8.2–8.5 against Laravel 12 and 13 (excluding PHP 8.2 + Laravel 13, which Laravel does not support), plus one `prefer-lowest` cell on PHP 8.2 that resolves every constraint to its floor and must pass tests *and* static analysis there. A database matrix runs the whole suite against MySQL 8.0, MariaDB 10.6, PostgreSQL 13, and SQL Server 2019, and an install job builds a fresh `laravel/laravel` application (oldest and newest supported pairing), requires the package from the local path, and walks `composer require` → auto-discovery → publish → migrate → a persisted execution → failure recovery → rollback, asserting each step with the commands a user would run. The audit runs only against the committed lock file — the lowest leg deliberately resolves old dev dependencies, which would always carry advisories.

The individual checks:

| Command | What it does |
|---------|--------------|
| `composer lint` | Laravel Pint in check mode (`composer format` applies fixes). |
| `composer analyse` | PHPStan via Larastan at level 8 over `src/`, `database/`, `tests/`, and `workbench/`. |
| `composer test` | PHPUnit. The configuration fails on risky tests, warnings, notices, and deprecations. |

Tests use Orchestra Testbench with an in-memory SQLite database by default. No external services or credentials are required, and connector tests must keep it that way: fake HTTP with `Http::fake()` rather than calling real APIs.

The database driver is selectable, so the suite can prove the engines the compatibility policy names. `Laraflow\Tests\TestCase::testDatabaseConnection()` reads:

```bash
# Defaults: in-memory SQLite, nothing to install.
composer test

# Any server Laravel's native drivers speak: mysql, mariadb, pgsql, sqlsrv.
LARAFLOW_TEST_DB_DRIVER=mysql \
LARAFLOW_TEST_DB_HOST=127.0.0.1 LARAFLOW_TEST_DB_PORT=3306 \
LARAFLOW_TEST_DB_DATABASE=laraflow_test \
LARAFLOW_TEST_DB_USERNAME=laraflow LARAFLOW_TEST_DB_PASSWORD=secret \
composer test
```

The matching PHP PDO extension must be installed (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlsrv`), and the named database must exist and be disposable — the suite migrates and truncates it. A test that can only be staged on one engine (for example switching SQLite's foreign-key enforcement off) must check `$this->databaseDriver()` and skip with a reason on the others, so a matrix run reports the skip rather than an error.

Tests should prove behaviour, not just the absence of errors: assert on persisted rows, outgoing requests, log records, and exception messages. Test doubles live in `tests/Support/` (for example `CrashingExecutionRepository`, which simulates a worker dying at a chosen persistence boundary, and `ArrayLogger`, an in-memory PSR-3 logger).

## Making changes

1. Branch from `main`.
2. Write or update tests. Every behaviour change needs a test; recovery and persistence changes need tests that exercise the failure path, not just the happy path.
3. Keep the public API small. Prefer extending an existing contract over adding a new one.
4. Keep persisted values JSON-serialisable. Do not introduce persistence of live PHP objects.
5. Never log, persist, or include in exception messages any credential or authentication header.
6. Run `composer test` and make sure everything passes.
7. Update `CHANGELOG.md` under *Unreleased* if you changed anything an application could observe.
8. Update the README or `docs/` if you changed documented behaviour.

### Code style

- Formatting is enforced by Laravel Pint (`pint.json`: Laravel preset plus `declare(strict_types=1)` and imported global classes). Run `composer format` before committing.
- Static analysis must pass at PHPStan level 8, over the tests and the playground as well as the package. There is no baseline file: a finding is fixed, not recorded. Treat user-supplied values (workflow step lists, connector factories) as untrusted and validate them at runtime.
- `@phpstan-ignore` has exactly one accepted use: a test that **deliberately** passes a value the declared type forbids, in order to prove the runtime rejects it. Those lines carry a comment saying so, and the tag names the single identifier it suppresses. Anywhere else — and anywhere in `src/` — fix the type or the code.
- Tests read `$this->app` as non-null (narrowed in `Laraflow\Tests\TestCase`) and reach for the base class's helpers rather than indexing models directly: `stepAt()`, `row()`, and `command()` assert the thing exists and tell you what was missing when it does not.
- Prefer constructor injection over facades and the `app()` helper inside package classes. Facades are fine in tests.
- Read timestamps through Laravel's `Date` facade so `Date::setTestNow()` controls them in tests.
- Line endings are LF; `.gitattributes` enforces this.

### Contributing a connector

Connectors are the one area where new code is actively wanted, and the recipe is short because the extension points do the work. Nothing below requires touching Laraflow internals — a test enforces that the bundled connectors do not either.

1. **Extend `Laraflow\Integration\HttpConnector`.** Declare `baseUrl()`, and override `headers()`, `authenticator()`, `errorMapper()`, and `paginator()` only where the provider differs from the defaults. The constructor is satisfied by the container; if you need more dependencies, declare them and call `parent::__construct()`.
2. **Never hold a credential.** Credentials are resolved per request through the `CredentialProvider`, so rotation works without a restart. Read them in `authenticate()`, not in a constructor.
3. **Map the provider's errors onto the shared types.** Extend `StatusErrorMapper` and fall back to `parent::map()`. An application should be able to catch `NotFoundException` without knowing which provider raised it. If the provider reports failure inside a `200` — Slack does — that is exactly what the mapper is for.
4. **Keep messages clean.** Build them from the request as the caller made it, and name the path, never the query string: a query string may carry a token.
5. **Declare a paginator** if the provider pages, choosing from `LinkHeaderPaginator`, `CursorPaginator`, and `PagePaginator`, or write one. Paging must stay lazy and bounded.
6. **Run the published contract test** against it, alongside your own tests for whatever is provider-specific:

```php
use Laraflow\Testing\ConnectorContract;

public function test_it_meets_the_connector_contract(): void
{
    config(['laraflow.integrations.crm.connections.default.credentials.token' => 'secret-token']);

    (new ConnectorContract)->assertHeldBy(
        app(LaraflowManager::class)->connector('crm'),
        credential: 'secret-token',
        probe: new ConnectorRequest('GET', '/ping'),
    );
}
```

It checks the things that are easy to get subtly wrong: that the connector holds no credential, classifies every error status into the shared vocabulary, returns errors rather than throwing them, and keeps the credential and the query string out of every message. It fakes HTTP and prevents stray requests, so a connector that reached the network fails rather than passing quietly.

A connector in a third-party package registers itself from that package's own service provider with `LaraflowManager::register()`; one in this repository is declared in `config/laraflow.php` like the bundled two. Either way, **no Laraflow file needs editing to add one** — if you find yourself wanting to, that is a bug in the extension points and worth an issue.

### Database migrations

Until the schema is frozen (roadmap Milestone 6), edit the existing migration files in place rather than adding new ones. Mention schema changes explicitly in the changelog, because there is no upgrade path between development snapshots.

### Commit messages

Use the imperative mood ("Add claim token to executions"), explain *why* in the body when the diff does not make it obvious, and reference issues where relevant.

## Pull requests

- One logical change per pull request.
- Describe what changed, why, and how it was tested.
- Expect review comments about recovery semantics, serialisation, and concurrency; those are the areas where this project is most careful.

## Reporting bugs

Include the PHP, Laravel, and database versions, the workflow and step definitions (or a minimal reproduction), the contents of the relevant `laraflow_*` rows if persistence is involved, and the exception with stack trace.

## Security

Do not report security issues in public issues. Follow [SECURITY.md](SECURITY.md).

## License

By contributing you agree that your contributions are licensed under the [MIT license](LICENSE).
