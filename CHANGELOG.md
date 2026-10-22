# Changelog

All notable changes to Laraflow are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until the first tagged release, breaking changes may land in any commit and are recorded under *Unreleased*.

## [Unreleased]

### Added

- Package migrations are now loaded automatically by the service provider, with an opt-out via `laraflow.migrations.load` / `LARAFLOW_LOAD_MIGRATIONS`.
- `laraflow-migrations` publish tag for applications that want to manage the migrations themselves.
- Package metadata: license, keywords, support links, `illuminate/database` dependency, `composer test` script.
- Documentation: installation and usage README, public API and stability levels (`docs/public-api.md`), compatibility and support policy (`docs/compatibility.md`), contributing guide, security policy, this changelog.
- `.gitattributes` enforcing LF line endings.

### Changed

- The service provider now merges the package configuration (`mergeConfigFrom`), so `config('laraflow.*')` has defaults without publishing.
- The GitHub connector token is cast to a string when the configuration value is missing, so resolving the connector no longer raises a `TypeError`; requests without a token will fail at the GitHub API instead.

### Decided (not yet implemented)

- `WorkflowRunner::run()` will return a `WorkflowExecution` handle instead of a `WorkflowContext`.
- `start()` and `execute()` will be added; `resume()` will be removed. See `docs/public-api.md`.

### Known limitations

- `resume()` is experimental and not safe for recovery.
- Execution is synchronous only; no retries, queue dispatch, or stale-execution recovery.
- Step outputs must be JSON-serialisable arrays.

## Foundations (unreleased, prior to this changelog)

- Composer package with Laravel auto-discovery.
- Connector, factory, manager, and authenticator abstractions with a GitHub connector prototype.
- `Workflow`, `Step`, `WorkflowContext`, `WorkflowRunner`, execution and step value objects, `ExecutionRepository` contract with an Eloquent implementation, and initial migrations.
- Unit and feature tests for the above.
