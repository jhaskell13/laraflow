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

The individual checks, which CI runs on PHP 8.2, 8.3, and 8.4:

| Command | What it does |
|---------|--------------|
| `composer lint` | Laravel Pint in check mode (`composer format` applies fixes). |
| `composer analyse` | PHPStan via Larastan at level 8 over `src/` and `database/`. |
| `composer test` | PHPUnit. The configuration fails on risky tests, warnings, notices, and deprecations. |

Tests use Orchestra Testbench with an in-memory SQLite database. No external services or credentials are required, and connector tests must keep it that way: fake HTTP with `Http::fake()` rather than calling real APIs.

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
- Static analysis must pass at PHPStan level 8. Do not add baseline entries or `@phpstan-ignore` comments; fix the type or the code. Treat user-supplied values (workflow step lists, connector factories) as untrusted and validate them at runtime.
- Prefer constructor injection over facades and the `app()` helper inside package classes. Facades are fine in tests.
- Read timestamps through Laravel's `Date` facade so `Date::setTestNow()` controls them in tests.
- Line endings are LF; `.gitattributes` enforces this.

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
