# Contributing to Laraflow

Thanks for your interest. Participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md). Laraflow is early-stage, so the most valuable contributions right now are focused ones: bug reports with reproductions, test coverage for recovery edge cases, and changes that advance the work recorded in [ROADMAP.md](ROADMAP.md).

## Before you start

- **Check the roadmap.** Work is ordered by dependency. A feature the roadmap defers (graph branching, a visual designer, new bundled connectors) is unlikely to be merged ahead of the work it depends on, however good the code is. Open an issue first to discuss anything the roadmap does not already name.
- **Open an issue for anything non-trivial.** Bug fixes with tests can go straight to a pull request. Design changes, new public API, and schema changes should be discussed first.
- **Read [docs/public-api.md](docs/public-api.md).** Changes to public classes need a changelog entry; changes to internal classes do not.

## Development setup

```bash
git clone https://github.com/jhaskell13/laraflow.git
cd laraflow
composer install
composer check   # code style + static analysis + tests
```

CI (`.github/workflows/ci.yml`) splits the work: a single quality job runs `composer validate --strict`, `composer audit`, lint, and static analysis; the test matrix covers PHP 8.2–8.5 against Laravel 12 and 13 (excluding PHP 8.2 + Laravel 13, which Laravel does not support), plus one `prefer-lowest` cell on PHP 8.2 that resolves every constraint to its floor and must pass tests *and* static analysis there. A database matrix runs the whole suite against MySQL 8.4, MariaDB 10.11 and 11.4, PostgreSQL 14 and 17, and SQL Server 2022, each at both ends of the dependency axis — Laravel 13 on the lock file, and Laravel 12 resolved `prefer-lowest` on PHP 8.2 — and each engine job ends with the install walk against that engine, in a second database the suite has not touched. The walk (`.github/actions/install-walk`, one definition shared with the SQLite install job, which runs it on the oldest and newest supported pairings) builds a fresh `laravel/laravel` application, requires the package from the local path, and proves the documented first-run story with the commands a user would run: auto-discovery registers every command, `laraflow:install --publish-migrations` publishes and migrates, `laraflow:make-workflow`, `laraflow:make-step`, and `laraflow:make-connector` write the classes, three `sed` edits name the steps and give one a side effect that fails once, `laraflow:start` runs the generated workflow to a persisted failure that `laraflow:execute` recovers from its checkpoint, the same workflow runs through the skeleton's database queue, a hand-written fan-out runs its members on the queue and joins them, a hand-written compensatable step is undone when its workflow fails, `laraflow:connectors --check` passes, the dashboard is absent until the application mounts it and then answers over real HTTP — denying everyone outside `local` until a rule says who may — and `migrate:rollback` removes the schema. The audit runs only against the committed lock file — the development set CI tests with, which applications never install — and not the lowest leg, which deliberately resolves old dev dependencies that would always carry advisories.

The individual checks:

| Command | What it does |
|---------|--------------|
| `composer lint` | Laravel Pint in check mode (`composer format` applies fixes). |
| `composer analyse` | PHPStan via Larastan at level 8 over `src/`, `database/`, `tests/`, and `workbench/`. |
| `composer test` | PHPUnit. The configuration fails on risky tests, warnings, notices, and deprecations. |

Tests use Orchestra Testbench with an in-memory SQLite database by default. No external services or credentials are required, and connector tests must keep it that way: fake HTTP with `Http::fake()` rather than calling real APIs.

The database driver is selectable, so the suite can prove the engines the compatibility policy names. `Laraflow\Tests\TestCase::databaseConnection()` reads:

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
7. Update `CHANGELOG.md` under *Unreleased* if you changed anything an application could observe. Breaking changes go under **Breaking**; retiring a public class or config key starts with `@deprecated` and a *Deprecated* entry naming the replacement and the planned removal, per [docs/compatibility.md](docs/compatibility.md#how-a-deprecation-works) — not with deletion.
8. Update the README or `docs/` if you changed documented behaviour.

### Code style

- Formatting is enforced by Laravel Pint (`pint.json`: Laravel preset plus `declare(strict_types=1)` and imported global classes). Run `composer format` before committing.
- Static analysis must pass at PHPStan level 8, over the tests and the playground as well as the package. There is no baseline file: a finding is fixed, not recorded. Treat user-supplied values (workflow step lists, connector factories) as untrusted and validate them at runtime.
- `@phpstan-ignore` has exactly one accepted use: a test that **deliberately** passes a value the declared type forbids, in order to prove the runtime rejects it. Those lines carry a comment saying so, and the tag names the single identifier it suppresses. Anywhere else — and anywhere in `src/` — fix the type or the code.
- Tests read `$this->app` as non-null (narrowed in `Laraflow\Tests\TestCase`) and reach for the base class's helpers rather than indexing models directly: `stepAt()`, `row()`, and `command()` assert the thing exists and tell you what was missing when it does not.
- Prefer constructor injection over facades and the `app()` helper inside package classes. Facades are fine in tests.
- Read timestamps through Laravel's `Date` facade so `Date::setTestNow()` controls them in tests.
- Line endings are LF; `.gitattributes` enforces this.

### Cutting a release

Releases are drafted by machinery and published by a person.

1. **Convert the changelog.** Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`, start a fresh empty `## [Unreleased]` above it, and update the compare links in the footer. The release workflow refuses a tag whose version has no changelog section.
2. **Land that on `main`** through a pull request like any other change, and wait for CI — the tag must point at a commit the full matrix has already passed.
3. **Tag it, annotated:** `git tag -a vX.Y.Z -m "vX.Y.Z" && git push origin vX.Y.Z`. Annotated, not lightweight — an annotated tag carries its own author, date, and message, and is what `git describe` and signature verification work with. `v0.2.0` is the one lightweight tag in the history and is left as it is rather than moved; every tag from 1.0 is annotated and, once the maintainer has a signing key configured, signed with `-s`.
4. The `Release` workflow re-runs the entire CI matrix against the tag and, only if everything passes, **drafts** a GitHub release with that version's changelog section as its notes. Nothing is published automatically.
5. **Read the draft, then publish it.** Publishing is the human decision in the loop; a draft that looks wrong is deleted, the problem fixed, and the tag re-cut.

#### Before the 1.0.0 tag, once

Four things only a maintainer's account can do, each a prerequisite for a claim the documentation now makes.

1. **Publish on Packagist.** Sign in at packagist.org with the GitHub account, *Submit* `https://github.com/jhaskell13/laraflow`, and enable the GitHub hook it offers so every future tag is picked up without a manual update. The README says `composer require laraflow/laraflow`; until this is done, that line is false. Check with `composer show laraflow/laraflow --all` from any machine.
2. **Configure tag signing.** Either a GPG key (`gpg --full-generate-key`, then `git config --global user.signingkey <id>` and `git config --global tag.gpgSign true`) or an SSH key (`git config --global gpg.format ssh` and `user.signingkey ~/.ssh/<key>.pub`), and upload the public half to the GitHub account so the tag shows *Verified*. Then step 3 above becomes `git tag -s v1.0.0 -m "v1.0.0"`.
3. **Confirm the soak schedule is live.** Scheduled workflows run only from the default branch, so the `Soak` workflow starts the night after Milestone 12 lands on `main`. The roadmap's first exit criterion is fourteen consecutive clean nightly runs across all six engines plus one multi-hour run per engine (`workflow_dispatch` with `duration: 7200`); the tag waits for that budget, not for a date.
4. **Enable GitHub Pages and Discussions.** Pages: *Settings → Pages*, source *GitHub Actions*, then set the repository variable `DOCS_DEPLOY` to `true` so the `Docs` workflow deploys. Discussions: *Settings → General → Features*. Both are referenced by the issue forms and the documentation site and have been pending since Milestone 11.

Then the five steps above, with `X.Y.Z` = `1.0.0`.

The dist archive an application vendors is trimmed by `.gitattributes` `export-ignore` lines: source, config, migrations, stubs, `docs/`, and the top-level README, CHANGELOG, LICENSE, SECURITY, CONTRIBUTING, and MAINTAINERS ship; tests, the workbench, CI configuration, and planning documents do not. `tests/Feature/DistArchiveTest.php` pins the archive's top-level contents, so adding a file at the root fails the build until it is either export-ignored or added to the list deliberately — the Milestone 12 audit found a stray 305 KB file that had shipped because this check was a sentence rather than a test.

### Database migrations

**The schema is frozen.** Shipped migrations are immutable: never edit them. A schema change arrives as a *new* dated migration, because an installed application must be able to reach the new schema by running `php artisan migrate` and must never be told to drop its tables.

`tests/Feature/Database/SchemaFreezeTest.php` enforces this — it fails if the schema the shipped migrations produce changes at all — and `UpgradePathTest` is the harness a new migration is tested through: it applies one to an already-populated database and asserts the data survives both the upgrade and its rollback. A schema change needs an explicit changelog entry describing its operational impact, and `docs/upgrading.md` is the contract it has to honour.

### Commit messages

Use the imperative mood ("Add claim token to executions"), explain *why* in the body when the diff does not make it obvious, and reference issues where relevant.

## Contributing a connector

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

**A connector for a service Laraflow does not ship** belongs in a package of your own rather than in this repository: [docs/community-connectors.md](docs/community-connectors.md) shows the package's shape and how it is listed, with the contract test as the review.

## Running the soak

The soak ([docs/soak.md](docs/soak.md)) is the suite that runs for a long time under load while workers are killed and containers are stopped, and then checks the invariants. It is not part of `composer test`.

```bash
composer soak                                  # five minutes of load on SQLite, then settling; worker kills only
composer soak -- --duration=120 --workers=2    # shorter, for a change to the soak itself
LARAFLOW_TEST_DB_DRIVER=pgsql composer soak    # an engine, through the suite's own variables
```

Settling stops when nothing is unfinished, or when the unfinished count has stopped falling (`--stall-after`, 180s) — not on a fixed clock, because a healthy drain can be slow. Each run writes `storage/soak/<timestamp>/` — every process's log, the timing records, the ledger of executions created, and `report.json` with `report.md` beside it — and exits non-zero if an invariant was violated or the run did not settle. `vendor/bin/testbench soak:verify --run-dir=storage/soak/<timestamp>` re-runs the invariant checks against the database as it stands.

In CI the `Soak` workflow runs nightly on every engine with the database and Redis containers stopped and started under load, and a person can start a longer run from the Actions tab with a duration. Its results are the job summary and an artifact per engine; nothing in it gates a pull request.

**Maintainer steps.** Scheduled workflows run only from the default branch, so the soak starts running the night after it lands on `main`; GitHub pauses a schedule after sixty days without a commit, and the Actions tab offers to re-enable it. To accept a run's numbers as the baseline, copy the `benchmarks` object from its `report.json` under the engine's driver name in `workbench/soak/baseline.json` and commit it with a note of which run it came from; the comparison is advisory, so a baseline that is never updated costs only an unhelpful sentence in the summary.

## Working on the documentation

`docs/` is both the Markdown GitHub shows and the source of the documentation site, built with VitePress ([decision 0008](docs/decisions/0008-documentation-site-on-vitepress.md)). To preview it:

```bash
cd docs
npm install
npm run dev
```

`npm run build` fails on a dead internal link, as the `Docs` workflow does on a pull request. Link between pages with relative `.md` links; a link out of `docs/` (to `CONTRIBUTING.md`, `SECURITY.md`) is rewritten to GitHub on the site. A new page needs a sidebar entry in `docs/.vitepress/config.mts`.

## Working on the tracing package

`packages/opentelemetry` is `laraflow/opentelemetry`, the one optional package ([decision 0011](docs/decisions/0011-one-optional-package.md)): a Composer package of its own, resolved against the core in this checkout through a path repository, and published by splitting the directory to its own repository. Work on it from its directory:

```bash
cd packages/opentelemetry
composer install
composer test
composer analyse
```

Its tests run against the real OpenTelemetry SDK with an in-memory exporter. What it records — span names, nesting, attributes, and what never appears on a span — is [docs/tracing.md](docs/tracing.md); a change there is a change to that document in the same pull request.

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
