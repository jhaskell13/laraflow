# Security Policy

## Supported versions

Security fixes land on `main` and are released in the newest version. The policy from the first tag onward: the **latest minor release** receives fixes, and the **previous minor** continues to receive them for 90 days after a newer minor is published. From 1.0 onward, the **last minor of an outgoing major** continues to receive them for 12 months after the next major is published — the long-term-support commitment, so an application has a year to make the changes a major asks for without running unpatched. Older versions do not receive fixes — upgrading is the remediation, and [docs/upgrading.md](docs/upgrading.md) is the procedure.

| Version | Supported |
|---------|-----------|
| `main` (development) | Yes |
| `0.7.x` (latest minor) | Yes |
| `0.6.x` (previous minor) | For 90 days after `0.7.0` |
| Last minor of the previous major (from 1.0) | For 12 months after the next major |
| Anything older | No — upgrade |

This table and [docs/releases.md](docs/releases.md#support-window) state one window; if they ever disagree, this file is the authority.

The trigger endpoints' signature verification ([docs/triggers.md](docs/triggers.md)) is in scope the way the claim discipline is: a way to have a signal accepted without a valid signature over the documented signed string, to learn a configured key, or to distinguish refusal reasons from the response would be a vulnerability. So is a way to make the server hash a body over `laraflow.triggers.max_body_bytes` before refusing it.

The dashboard's actions ([docs/dashboard.md](docs/dashboard.md)) are in scope too: a way to perform one cross-origin (the action routes check the session's CSRF token themselves, not only through the application's `web` middleware), to perform one with a request the configured view or action rule refuses, or to read a step's exception text that the configured `ErrorRedactor` would have rewritten.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security problems.

Report vulnerabilities privately using [GitHub's private vulnerability reporting](https://github.com/jhaskell13/laraflow/security/advisories/new) for this repository. Include:

- A description of the issue and its impact.
- Steps or a proof of concept to reproduce it.
- Affected versions or commit range, if known.
- Any suggested fix.

You should receive an acknowledgement within 7 days. We will keep you informed as the report is triaged and fixed, and will credit you in the advisory unless you ask otherwise.

## Scope

[docs/threat-model.md](docs/threat-model.md) is the reasoning behind this list: the trust boundaries, the attackers each one assumes, the invariants the package defends, and the residual risk at each boundary. Read it if you want to argue with the scope rather than work within it.

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
- An absolute URI an application hands to `Connector::request()` reaching the host it names, with the connection's credentials on it. The transport sends the URI the caller gave, because a connector legitimately calls a signed upload endpoint or a redirect target off the provider's main host; the host guard is on the links *Laraflow* chooses to follow from a provider's response (`pages()`), which is the case the application did not author. Building a request URI out of a provider's response or workflow input and passing it to `request()` is the application's call to check — see [docs/connectors.md](docs/connectors.md#pagination).
- Resource exhaustion caused by an application's own retry configuration. Retries are off by default, every bound is documented, and an infinite loop caused by an application calling `run()` repeatedly is not a package vulnerability.

## Handling of secrets in the package

- Credentials are read from configuration and environment variables — or from a `CredentialProvider` the application binds — and are never written to the `laraflow_*` tables.
- Credentials are resolved when a request is built, not when a connector is constructed, and never reach the `Connection` object a connector holds. A cached connector instance carries no credential; a test walks every property of every Laraflow object in the connector graph to keep it that way.
- Every credential is a `Laraflow\Support\Secret`, which renders as `[redacted]` in string context, JSON, `print_r`, `var_dump`, `var_export`, the array cast, and `serialize`, and yields its value only to `reveal()`. The value is not stored as a property, so renderers that bypass `__debugInfo` have nothing to read.
- The `Authenticator` contract injects credentials into outbound request options at send time. Connectors must not include request options in exception messages, and Laraflow's own frames keep them out of stack-trace arguments. Two sinks beneath Laraflow remain the application's to configure: `TransportException` keeps the HTTP client's own exception as its `previous`, whose message under Guzzle 7 can carry the full request URL including its query string (Guzzle 8 redacts it), and Laravel's exception handler renders the whole chain; and the bundled transport relies on Guzzle's stripping of `Authorization` on cross-origin redirects — an application-bound `HttpTransport` must do the same. Frames belonging to the HTTP client beneath a connector do carry the authenticated options when `zend.exception_ignore_args` is off, as they would for any PHP HTTP client; production configurations should leave that directive on, and an exception renderer that displays frame arguments should not be exposed publicly.
- Lifecycle events never carry workflow input, step output, or context, and there is no option to include them. Failure events and log records carry the exception class and message; the message can be disabled for events (`laraflow.events.include_error_messages`) and rewritten everywhere Laraflow retains it by a configured `ErrorRedactor` (`laraflow.errors.redactor`). One class of message is rewritten before any redactor sees it: a database exception, whose message Laravel builds from the SQL with every bound value substituted in — the workflow's own data, when the failing statement was a checkpoint or a step's query. Laraflow retains the driver's error and the statement with its placeholders, never the values (found by the soak suite, Milestone 12). The dashboard's flashed action outcomes use the same redacted, length-bounded form. A trigger endpoint answers an *unverified* caller with a fixed `401` or `413` and nothing else; once a request has verified, a refusal carries a machine-readable status and the runtime's own wording for why ([docs/triggers.md](docs/triggers.md#what-the-endpoint-answers)) — bounded and stripped of control bytes, as the log line is. This paragraph claimed those responses carried no text at all, which the contract document and the code had never agreed with; the Milestone 12 review corrected the claim and added the bounding it implied.
- The queue payload carries only the execution id; workflow input never reaches the queue backend.
