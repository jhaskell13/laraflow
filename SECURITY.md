# Security Policy

## Supported versions

Laraflow has not yet had a tagged release. Security fixes are applied to the `main` branch only. Once releases are tagged, this table will list which minor versions receive fixes; the intent is to support the latest minor release and the previous one for 90 days after a new minor is published.

| Version | Supported |
|---------|-----------|
| `main` (development) | Yes |

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security problems.

Report vulnerabilities privately using [GitHub's private vulnerability reporting](https://github.com/jhaskell13/laraflow/security/advisories/new) for this repository. Include:

- A description of the issue and its impact.
- Steps or a proof of concept to reproduce it.
- Affected versions or commit range, if known.
- Any suggested fix.

You should receive an acknowledgement within 7 days. We will keep you informed as the report is triaged and fixed, and will credit you in the advisory unless you ask otherwise.

## Scope

In scope:

- Any way for workflow input, step output, or context data to execute code or escape its intended storage.
- Leakage of connector credentials or authentication headers through logs, persisted records, exceptions, or events.
- Flaws in execution claiming or persistence that let an attacker with database access alter the control flow of a workflow beyond what database access alone implies.
- Vulnerabilities in the bundled connectors.

Out of scope:

- Vulnerabilities in third-party dependencies (report those upstream; let us know so we can bump the constraint).
- Issues that require an attacker to already control the application's code, configuration, or environment variables.
- Missing hardening in features the roadmap explicitly marks as not yet implemented (for example, there is currently no retry limit; an infinite loop caused by an application calling `run()` repeatedly is not a package vulnerability).

## Handling of secrets in the package

- Credentials are read from configuration and environment variables and are never written to the `laraflow_*` tables.
- The `Authenticator` contract injects credentials into outbound request options at send time. Connectors must not include request options in exception messages.
- Future lifecycle events and logging (roadmap Milestones 2 and 4) will redact step data by default.
