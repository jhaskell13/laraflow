# Security Policy

## Supported versions

Security fixes land on `main` and are released in the newest version. The policy from the first tag onward: the **latest minor release** receives fixes, and the **previous minor** continues to receive them for 90 days after a newer minor is published. Older versions do not receive fixes — upgrading is the remediation, and [docs/upgrading.md](docs/upgrading.md) is the procedure.

| Version | Supported |
|---------|-----------|
| `main` (development) | Yes |
| `0.1.x` (latest minor) | Yes |
| Previous minor | For 90 days after the next minor |
| Anything older | No — upgrade |

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
- Vulnerabilities in the bundled connectors, and in the connector framework: a way for a provider's response to redirect a request carrying credentials to another host, to make a paginator follow a link it should refuse, or to reach a log, event, or execution record with anything but the path of the request.

Out of scope:

- Vulnerabilities in third-party dependencies (report those upstream; let us know so we can bump the constraint).
- Issues that require an attacker to already control the application's code, configuration, or environment variables.
- Missing hardening in features the roadmap explicitly marks as not yet implemented.
- Frame arguments of the HTTP client beneath a connector appearing in an exception trace when `zend.exception_ignore_args` is off. Laraflow marks its own parameters sensitive and keeps every message it produces free of credentials, but it cannot annotate Laravel's or Guzzle's frames; leave that directive on in production, as the PHP defaults do, and do not expose an exception renderer that shows frame arguments.
- Unbounded growth of the `laraflow_*` tables in an application that has not configured retention. Bounds ship disabled by design (see [docs/retention.md](docs/retention.md)); choosing not to set them is a capacity decision, not a package vulnerability.
- Resource exhaustion caused by an application's own retry configuration. Retries are off by default, every bound is documented, and an infinite loop caused by an application calling `run()` repeatedly is not a package vulnerability.

## Handling of secrets in the package

- Credentials are read from configuration and environment variables — or from a `CredentialProvider` the application binds — and are never written to the `laraflow_*` tables.
- Credentials are resolved when a request is built, not when a connector is constructed, and never reach the `Connection` object a connector holds. A cached connector instance carries no credential; a test walks every property of every Laraflow object in the connector graph to keep it that way.
- Every credential is a `Laraflow\Support\Secret`, which renders as `[redacted]` in string context, JSON, `print_r`, `var_dump`, `var_export`, the array cast, and `serialize`, and yields its value only to `reveal()`. The value is not stored as a property, so renderers that bypass `__debugInfo` have nothing to read.
- The `Authenticator` contract injects credentials into outbound request options at send time. Connectors must not include request options in exception messages, and Laraflow's own frames keep them out of stack-trace arguments. Two sinks beneath Laraflow remain the application's to configure: `TransportException` keeps the HTTP client's own exception as its `previous`, whose message under Guzzle 7 can carry the full request URL including its query string (Guzzle 8 redacts it), and Laravel's exception handler renders the whole chain; and the bundled transport relies on Guzzle's stripping of `Authorization` on cross-origin redirects — an application-bound `HttpTransport` must do the same. Frames belonging to the HTTP client beneath a connector do carry the authenticated options when `zend.exception_ignore_args` is off, as they would for any PHP HTTP client; production configurations should leave that directive on, and an exception renderer that displays frame arguments should not be exposed publicly.
- Lifecycle events never carry workflow input, step output, or context, and there is no option to include them. Failure events and log records carry the exception class and message; the message can be disabled for events (`laraflow.events.include_error_messages`) and rewritten everywhere Laraflow retains it by a configured `ErrorRedactor` (`laraflow.errors.redactor`).
- The queue payload carries only the execution id; workflow input never reaches the queue backend.
