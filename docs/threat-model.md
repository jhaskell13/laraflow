# Threat model

This document states what Laraflow is defending, against whom, and where it stops. [SECURITY.md](../SECURITY.md) says what is in scope for a vulnerability report and how to report one; this says *why* that list has the shape it does, so a reviewer can disagree with the reasoning rather than guess at it.

It exists because a security review needs something to review *against*. Milestone 12's review is conducted against this document, and its findings are published with it.

## What an attacker wants

1. **Credentials.** The tokens a connector authenticates with. Laraflow resolves them per request and never persists them, so the realistic prize is a leak through a log line, a persisted error, an event, an exception message, or a stack frame.
2. **Control of a workflow's execution.** Making a step run that should not have, skipping one that should have, resuming a wait that nothing satisfied, or making a side effect happen twice.
3. **The side effects themselves.** A workflow exists to charge a card, ship an order, or call an API. An attacker who can start, signal, or re-run an execution can cause those to happen.
4. **Workflow data.** Inputs, step outputs, and context — which are application data Laraflow stores on the application's behalf.
5. **Availability.** Filling the tables, exhausting workers, or wedging the recovery path.

## Trust boundaries

Each boundary lists what crosses it, what Laraflow assumes, what it checks, and what remains.

### 1. The application's own code, configuration, and environment — **trusted**

Workflow classes, step classes, `config/laraflow.php`, and the environment are the application. An attacker who controls them has already won, and defending against them would mean defending Laravel against its own `app/` directory. This is why "issues that require an attacker to already control the application's code, configuration, or environment variables" is out of scope.

**Residual:** a workflow author is trusted completely. Laraflow does not sandbox steps.

### 2. The database — **trusted for integrity, assumed shared**

The claim is the only authority over who runs an execution ([decision 0002](decisions/0002-claims-are-compare-and-set.md)), and that authority rests entirely on the database honouring a compare-and-set `UPDATE ... WHERE`. Laraflow assumes the engine is correct and that nobody else writes its tables — `public-api.md` says *do not write to them*.

**Checked:** every state transition goes through the state machine; every step-row write is claim-guarded; the `version` counter exists so a compare-and-set that rewrites identical values is still counted on engines reporting rows *changed* rather than *matched*.

**Residual:** an attacker with write access to `laraflow_*` tables can redirect control flow. This is in scope only "beyond what database access alone implies" — because an attacker who can write your tables can also write your orders table, and the package cannot be the thing that saves you.

### 3. The queue broker — **semi-trusted**

The payload carries only an execution id. That is a deliberate boundary: a broker compromise, a leaked Horizon dashboard, or a queue backend readable by another tenant yields ids, not workflow inputs.

**Checked:** duplicate deliveries resolve through the claim; a delivery for a `failed` execution is refused unless an operator opted in; the job is given no retry authority by default so two retry systems cannot multiply.

**Residual:** an attacker who can inject jobs can cause executions to be *attempted*. They cannot choose which workflow runs or what input it gets — only the id — and every attempt still meets the claim, the status rules, and the tenant ceilings.

### 4. The trigger endpoints — **untrusted, the hostile edge**

The only place Laraflow accepts input from the open internet, and the boundary most deserving of review.

**Checked, in this order, before anything is read:** body size against `laraflow.triggers.max_body_bytes` (a declared `Content-Length` over the limit is refused without hashing the body — hashing an attacker-chosen body before refusing it is itself the attack); then HMAC-SHA256 over timestamp, execution id, signal name, and body hash, compared with `hash_equals`, against `laraflow.triggers.key` or any key in `previous_keys`, within `laraflow.triggers.tolerance` seconds. Every verification failure is the same `401`, with the reason logged and never returned — so refusals cannot be distinguished from outside. Existence of an execution is checked only *after* verification, so an unsigned caller cannot enumerate ids. There is no environment bypass, and a mount with no key, or one under 32 bytes, refuses at the mount.

**Residual:** a captured signature can be replayed inside the tolerance window. The window is bounded by the signal contract — a replay overwrites the same wait or is refused — and documented with its defences in [triggers.md](triggers.md#replay). Keys carry no audience, so a key must not be shared between environments.

### 5. The dashboard — **authenticated, but assume the browser is hostile**

**Checked:** nothing is registered until an application calls `Dashboard::routes()`; mounted in production without an authorization rule it denies everyone; the action routes verify the session's CSRF token themselves rather than trusting the application's middleware stack to include it, and refuse a state-changing request with no session at all; `read_only` removes the actions entirely; the start form resolves a name to a `Workflow` subclass before anything is constructed; flashed outcomes carry the same redacted, length-bounded error form as everything else Laraflow retains.

**Residual:** the dashboard shows execution inputs, outputs, and context to anyone the application's rule admits. It is an operator tool, and the authorization rule is the whole of the access control.

### 6. A provider's response to a connector — **untrusted data**

A provider Laraflow calls may be compromised, or may simply be hostile; its response is parsed, mapped, and sometimes *followed*.

**Checked:** a next-page link is followed only after its host, scheme, and port are compared against the connection's — a provider cannot redirect a credentialed request to a host it chose; a repeated page, an absent paginator, and the page budget each refuse rather than truncate, because a short list that looks complete is the worst outcome; error messages name connection, method, path, and status — never the query string, which may carry a token; the request a mapper sees is the one made *before* authentication, so nothing an authenticator added can reach a message.

**Residual:** an absolute URI the *application* hands to `request()` reaches the host it names, with the connection's credentials. The transport sends what the caller gave it, because a connector legitimately calls a signed upload endpoint off the provider's main host. Building a URI out of a provider's response and passing it to `request()` is the application's to check ([connectors.md](connectors.md#pagination)).

### 7. The console and the operator — **trusted, bounded anyway**

Someone running `artisan` has shell access. The bounds exist to prevent accident, not attack: destructive and bulk commands require a filter, refuse to act on everything, confirm in production, and offer a dry run that predicts the real run exactly.

### 8. The supply chain — **a shared responsibility**

GitHub Actions are pinned to commit SHAs rather than tags, Dependabot proposes the bumps, and CI runs `composer audit` against the committed lock file. The lock file pins what CI tests with; applications resolve `composer.json`'s constraints themselves.

**Residual:** vulnerabilities in third-party dependencies are reported upstream. Tag signing is a maintainer action, recorded in [CONTRIBUTING.md](../CONTRIBUTING.md).

## Attacker models the review works through

| Attacker | Capability | Primary question |
|---|---|---|
| Unauthenticated internet caller | Can reach the mounted trigger endpoints | Can a signal be accepted without a valid signature, a key be learned, or refusal reasons be distinguished? |
| Low-privilege dashboard user | Passes the view rule, not the action rule | Can an action be performed cross-origin, or one the rule refuses? Can redacted error text be read? |
| A second worker | Races every claim | Can two workers run one execution, double-count an attempt, or both pass one tenant ceiling for longer than the documented instant? |
| A compromised provider | Controls every response byte | Can it redirect a credentialed request, make paging follow a link it should refuse, or reach a log, event, or record with anything but the path? |
| A log or event reader | Reads everything Laraflow emits | Can a credential, a payload, or an unredacted message be recovered? |
| A database reader | Reads `laraflow_*` | Can a credential be recovered? (It must not be there at all.) |

## Invariants the package defends

These are the statements a finding would falsify:

1. One execution is run by at most one worker at a time, decided by the claim and nothing else.
2. A credential never reaches a `laraflow_*` table, a log line, an event, or an exception message produced by Laraflow.
3. A reserved `laraflow.` signal name cannot be reached through the public signal entry, in any casing.
4. No lifecycle event carries workflow input, step output, or context — including by way of an error message: a database exception's message is retained without its bound values, since those *are* the payload when a checkpoint fails.
5. A trigger request that does not verify changes nothing and reveals nothing but `401`.
6. A dashboard action cannot be performed without a session and a matching CSRF token.
7. A next-page link is followed only to the host, scheme, and port the connection named.
8. Every SQL fragment the package builds is parameterised or constant.
9. A redactor configured by the application sees every error message Laraflow retains, before it is retained.

## What the first review found

Milestone 12's review was run against this document, in the dimensions it names. It is recorded here because a threat model with no findings beside it reads like a document nobody used.

**Clean on the surfaces most likely to carry a vulnerability.** Every raw SQL fragment in the package is a constant or parameterised. Every dashboard URL is `route()`-generated and every value echoed into markup or an attribute goes through Blade's escaping; the one unescaped echo is an integer cast. The live-refresh script fetches same-origin, parses with an inert `DOMParser`, and swaps server-rendered markup that escaping has already made safe. There is no `unserialize`, `eval`, `exec`, or dynamic include anywhere in `src/`. A workflow named by an operator is checked with `is_subclass_of` **on the string** and refused before anything is constructed, as boundary 5 claims. Trigger verification compares with `hash_equals`, hashes the body once however many keys a rotation carries, measures the body before hashing it, and holds every key — current and previous — to the same 32-byte floor.

**Three findings, all fixed with the tests that would have caught them.**

1. **A signal name was held to a weaker standard than the identifiers beside it.** Correlation and tenant ids are refused when they carry control characters or are not UTF-8, for a reason stated in the code: these reach Laravel's log context, "where a newline lets a crafted identifier forge a second log line". A signal name reaches the same sinks — a column, the log context, a lifecycle event, a `--json` document, a dashboard page — and had only a length and a reserved-prefix check. The rule now lives in one place (`SafeIdentifier`) and both use it.
2. **A refusal could become a `500`.** The trigger endpoint echoed the signal name and the refusal's wording into its JSON response without the bounding and control-byte stripping the log line has had since Milestone 10. A name that is not UTF-8 made `JsonResponse` throw while encoding the refusal, turning a clean `422` into a rendered stack trace. Reachable through a named trigger, whose resolver builds the signal from a request body that no URL rule constrains; *not* reachable through the signed endpoint, where the HTTP stack rejects a malformed URL with a `400` before routing — a boundary now recorded by a test.
3. **[SECURITY.md](../SECURITY.md) misdescribed its own surface**, claiming trigger responses "carry no exception text at all" where [triggers.md](triggers.md#what-the-endpoint-answers) documents the opposite and the code follows triggers.md. A security policy that misstates the attack surface sends a reviewer past the path worth reading. Corrected.

The review also acted on a dependency risk rather than a defect: PHP 8.2 reaches security end-of-life on 31 December 2026, and 1.0 is the last release the drop policy allows to raise the floor ([compatibility.md](compatibility.md#supported-versions)).

## Explicitly out of scope

The full list is in [SECURITY.md](../SECURITY.md#scope). The ones worth repeating, because each is a decision rather than an oversight:

- **Stack frames beneath a connector** carrying authenticated options when `zend.exception_ignore_args` is off. Laraflow marks its own parameters sensitive; it cannot annotate Laravel's or Guzzle's frames.
- **Unbounded table growth with retention unconfigured.** Bounds ship disabled by design; choosing not to set them is a capacity decision.
- **Resource exhaustion from an application's own retry configuration.** Retries ship off, and every bound is documented.
- **An absolute URI the application passes to `request()`** reaching the host it names. See boundary 6.
