# Contributing to Laraflow

Thanks for your interest. Laraflow is early-stage, so the most valuable contributions right now are focused ones: bug reports with reproductions, test coverage for recovery edge cases, and changes that advance the current milestone in [ROADMAP.md](ROADMAP.md).

## Before you start

- **Check the roadmap.** Work is ordered by dependency. A feature from a later milestone (queues, retries, branching, new connectors) is unlikely to be merged before the execution and recovery contract in Milestone 1 is proven, however good the code is. Open an issue first to discuss anything beyond the current milestone.
- **Open an issue for anything non-trivial.** Bug fixes with tests can go straight to a pull request. Design changes, new public API, and schema changes should be discussed first.
- **Read [docs/public-api.md](docs/public-api.md).** Changes to public classes need a changelog entry; changes to internal classes do not.

## Development setup

```bash
git clone https://github.com/jhaskell13/laraflow.git
cd laraflow
composer install
composer test
```

Tests use Orchestra Testbench with an in-memory SQLite database. No external services or credentials are required, and connector tests must keep it that way: fake HTTP with `Http::fake()` rather than calling real APIs.

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

- PSR-12, PHP 8.2+ features where they clarify intent (readonly properties, enums, named arguments).
- Line endings are LF; `.gitattributes` enforces this.
- Match the surrounding code. A formatter will be added in Milestone 6; until then, keep diffs free of unrelated reformatting.

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
