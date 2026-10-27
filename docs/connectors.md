# Connectors

This document is the contract for Laraflow's integration layer: how a connector is defined and registered, how it holds an account's credentials without ever holding them, how it speaks HTTP, how it reports errors, how it pages, what it announces, and how it is tested. It builds on the retry semantics in [retry-and-failure-policy.md](retry-and-failure-policy.md) and the observability model in [inspection-and-observability.md](inspection-and-observability.md).

Everything stated here is exercised by the suites under `tests/Feature/Integration/` and `tests/Unit/Integration/`. The three invariants that matter most each have a test that fails if they stop holding: no bundled connector depends on anything internal, and the shipped defaults are the documented ones (`tests/Feature/ProjectContractsTest.php`); no Laraflow object in a connector's graph holds a credential (`tests/Feature/Integration/SecretExposureTest.php`). If the code and this document disagree, one of them has a bug.

## The principle

A connector is a *description* of a provider, not a client library. It says where the provider lives, how it authenticates, how it says no, and how it says "there is more". Everything else — resolving credentials, retrying a dropped connection, holding to a rate limit, timing the call, announcing it — is the framework's, and is the same for every connector. That is what makes a third-party connector possible without touching Laraflow: the extension points are the whole job, and a test fails if Laraflow's own connectors ever need more than them.

The second principle is that **a connector object holds no credential**. Credentials are resolved when a request is built, and the object a connector keeps for the application's lifetime can be dumped, logged, or listed without redaction. Rotation is then a matter of changing what the credential provider returns.

## Connectors and connections

A **connector** is a class, registered once under a name. A **connection** is one account on it — the production GitHub organisation and the staging one, one tenant's Slack workspace and another's. The same class serves every connection; a second account is a second configuration entry, not a second class.

```php
'integrations' => [
    'github' => [
        'connector' => \Laraflow\Integration\Github\GithubConnector::class,
        'default' => env('LARAFLOW_GITHUB_CONNECTION', 'default'),
        'connections' => [
            'default' => ['credentials' => ['token' => env('LARAFLOW_GITHUB_TOKEN')]],
            'enterprise' => [
                'base_url' => 'https://ghe.example/api/v3',
                'credentials' => ['token' => env('GHE_TOKEN')],
            ],
        ],
    ],
],
```

```php
$laraflow->connector('github');                // the default connection
$laraflow->connector('github', 'enterprise');  // another account, same class
```

A connector section holds **`connector`, `default`, and `connections`, and nothing else**. A setting left at the connector level — the original `integrations.github.token` — is refused with the path it belongs at, rather than being silently read by nothing. An unknown connection is refused naming the configured ones.

### Registration

Declaring `connector` in configuration registers the class at boot. It is built through the container on first use, once per connection, with its `Connection` passed by name and everything else autowired — so a connector declares its other dependencies as ordinary constructor parameters. A misnamed class fails at boot, not on first request.

A package registers from its own service provider instead, with the class or with a closure that receives the `Connection`:

```php
app(LaraflowManager::class)->register('crm', CrmConnector::class);
app(LaraflowManager::class)->register('crm', fn (Connection $c) => new CrmConnector($c, ...));
```

Either way no Laraflow file is edited. The bundled GitHub and Slack connectors are registered through the same configuration entries as anyone else's; there is no privileged path.

Instances are cached per connection until `forget()`. Because credentials are not on them, the cache never needs invalidating for a rotation.

### What a connection may set

| Key | Overrides | Meaning |
|---|---|---|
| `credentials` | — | The connection's secrets, read per request by the `CredentialProvider` and **stripped** from the `Connection` the connector holds. |
| `base_url` | the class | Point the same class at another host. |
| `timeout`, `connect_timeout` | the class, then `laraflow.http.*` | Seconds. |
| `headers` | merged over the class's | Sent with every request; a request's own headers win over both. |
| `retries` | merged over `laraflow.http.retries` | In-attempt transport retries; see below. |
| `rate_limit` | merged over `laraflow.http.rate_limit` | A ceiling Laraflow holds itself to; `false` lifts an inherited one. See below. |
| `max_pages` | `laraflow.http.pagination.max_pages` | The page budget. |
| `throw_on_error` | the class's `throwsOnError()` | Have `request()` throw a mapped error rather than return it. |
| `api_version` | the class's `apiVersion()` | Pin another provider API version for this account. |

Anything else is the connector's own to read with `Connection::get()` and `Connection::require()`, which treats a blank value as missing and names the configuration path — and the environment variable, if told it — in the error.

## Credentials

The `CredentialProvider` contract answers "what are this connection's secrets?" and is called **when a request is built, every time**. The default reads `…connections.{name}.credentials` from the config repository on each call; an application binds its own to read a vault, a database row, or a per-tenant store:

```php
$this->app->singleton(CredentialProvider::class, VaultCredentialProvider::class);
```

Three consequences follow, each covered by test:

- **Rotation needs no rebuild.** The next request uses the new value. No cache to flush, no process to restart.
- **A cached connector holds no credential.** The `credentials` key is stripped from the `Connection` before the connector sees it. `tests/Feature/Integration/SecretExposureTest.php` walks every property of every Laraflow object reachable from a connector and the manager, and fails if the token is in any of them.
- **A missing credential is found at the first request, not at resolution.** `connector('github')` succeeds with no token configured — there is nothing on the object to check — and the first `request()` fails with `ConnectorConfigurationException` naming `…connections.default.credentials.token` and `LARAFLOW_GITHUB_TOKEN`.

### `Secret`

Every credential is a `Laraflow\Support\Secret`. It renders as `[redacted]` in string context, `json_encode`, `print_r`, `var_dump`, `var_export`, the array cast, and `serialize`; the value is reached only by `reveal()`, which an authenticator calls at the one place it is needed.

The value is not stored on the object. `var_export` and the array cast read properties directly and ignore `__debugInfo`, so a private property would still leak through them; a `WeakMap` keyed by instance has nothing for them to read. An unserialized `Secret` carries no value at all — a credential that rode through a queue payload or a cache would otherwise sit in that store in clear text — and `reveal()` on it throws `SecretRedactedException`. Secrets are not cloneable.

### Authenticators

An `Authenticator` injects credentials into a request's options. It receives the `Credentials` on every call rather than holding them:

```php
public function authenticate(array $requestOptions, Credentials $credentials): array;
```

`BearerTokenAuthenticator` ships: `Authorization: Bearer` from a named credential (`token` by default), naming the environment variable in the missing-credential error. A provider with another scheme — a signed request, a header pair, basic auth — is a class implementing the interface, returned from the connector's `authenticator()`.

## Speaking HTTP

`HttpConnector` is the base class for connectors that speak HTTP, which is most of them. A subclass describes the provider:

```php
class CrmConnector extends HttpConnector
{
    protected function baseUrl(): ?string          { return 'https://crm.example/api/v2'; }
    protected function headers(): array            { return ['Accept' => 'application/json']; }
    protected function authenticator(): Authenticator { return new BearerTokenAuthenticator('token', 'CRM_TOKEN'); }
    protected function errorMapper(): ErrorMapper  { return new CrmErrorMapper; }   // optional
    protected function paginator(): ?Paginator     { return new CursorPaginator; }   // optional
    protected function apiVersion(): ?string       { return '2024-06'; }             // optional
}
```

and inherits `request()`: resolve this request's credentials, authenticate, send through the transport pipeline, map the response to an error if it is one. Options resolve **connector class → connection → `laraflow.http` defaults**, so a class states what its provider needs, a connection overrides it for one account, and an application sets fleet-wide limits once.

### The transport

`HttpTransport` puts an authenticated request on the wire. The default, `LaravelHttpTransport`, uses Laravel's HTTP client, so `Http::fake()` works in tests and the client's global middleware applies. Bind another implementation to replace the wire for every connector; override `transport()` to do it for one.

The transport's `$request` parameter is `#[\SensitiveParameter]`, because by then the options carry the credential. Laraflow's own stack frames therefore never show it. The framework's frames beneath — `PendingRequest::send` — do when `zend.exception_ignore_args` is off, as they would for any PHP HTTP client; `SECURITY.md` says what to do about that.

Headers are merged in the transport — connection defaults under the request's own, the request winning — and handed over in one options array, because the client's own recursive merge turns two values for one header into a list.

A `TransportException` means no HTTP response was obtained: refused, timed out, reset. Its message names the **host alone** — not the URL, whose query string may carry a token, and never the options.

### The pipeline

Behaviour the options enable is layered around the base transport, innermost first:

1. **Throttle** (`rate_limit`), so a retried attempt is counted like any other.
2. **Retry** (`retries`).
3. **Observe**, always — so one logical call is one event measuring what the caller actually waited, with `attempts` saying how many wire attempts went into it.

With the shipped defaults, a request goes straight to the wire, observed.

## Responses

A `ConnectorResponse` keeps the body as received and decodes explicitly:

| Method | Returns |
|---|---|
| `raw()` | The bytes. |
| `json(?dotKey, default)` | The body decoded as JSON **whatever the server declared** — some send JSON as `text/plain`. Malformed JSON throws `ResponseDecodingException`, which names the status and content type and never the body. |
| `body()` | The decoded value when the server declared JSON (malformed is then an error: the server said one thing and sent another), the raw string otherwise, `null` for an empty body. |
| `header(name)` | First value, case-insensitive. |
| `toArray()` | `status`, `headers`, `body` — a durable form for step output. |

Until Milestone 5, a non-JSON body was `null`, indistinguishable from a JSON `null`. An HTML maintenance page and an empty result looked the same.

## Errors

### Mapping

An `ErrorMapper` decides whether a response is an error and which kind. Providers disagree about how to say no: most use the status, Slack answers `200` with `{"ok": false}`, GitHub reports an exhausted rate limit as `403`. The default `StatusErrorMapper` goes by status; a connector supplies its own from `errorMapper()`, extending the default and falling back to `parent::map()`.

Whatever the provider's convention, the result is one of five **shared** types under `Laraflow\Exceptions\Connector`, so an application catches `NotFoundException` without knowing which provider raised it:

| Type | By status | Slack's `error` codes |
|---|---|---|
| `AuthenticationFailedException` | 401, 403 | `invalid_auth`, `token_revoked`, `missing_scope`, … |
| `NotFoundException` | 404 | `channel_not_found`, `user_not_found`, … |
| `RateLimitedException` | 429 (GitHub: 403 with `X-RateLimit-Remaining: 0`) | `ratelimited` |
| `ClientErrorException` | other 4xx | everything else |
| `ServerErrorException` | 5xx | — |

All extend `RequestFailedException`, which carries the `response`.

### Returned, not thrown

`request()` **returns** an error response with its exception attached: `failed()` is then true — even for a `200` the mapper rejected — `error()` returns the exception, and `throw()` raises it. A connection sets `throw_on_error` to have `request()` throw instead. The default preserves the original contract that an error status is a result to inspect; `throw()` is how a caller opts into the other behaviour, and it chains:

```php
$repo = $github->request(new ConnectorRequest('GET', '/repos/acme/app'))->throw()->json();
```

### Messages

`RequestFailedException::fromResponse()` is the one place a provider error becomes a message, and two things about it are deliberate. It is given the request **as the caller made it**, before authentication, so nothing an authenticator added can reach a message. And it names the **path alone**, because a query string is where a credential travels when a provider wants one there:

```
Connector [github:default] received 404 for GET /repos/acme/missing: Not Found
```

The provider's own explanation is taken from the usual JSON fields and truncated. Like every message Laraflow retains, it passes the configured `ErrorRedactor` before it reaches the execution record, a log, or an event.

Two sharp edges, recorded at the Milestone 6 audit: `throw()` raises a generic `RequestFailedException` for **any** non-2xx the mapper declined to classify — including a `304 Not Modified`, which the mapper deliberately does not consider an error, so conditional-GET connectors should inspect rather than `throw()`. And header names merge **case-sensitively** at every layer: a connection configuring `accept:` under a class declaring `Accept:` sends both headers rather than overriding. Spell header names the way the layer below you spelled them.

## Retries and rate limits

Both are specified alongside the step retry policy in [retry-and-failure-policy.md](retry-and-failure-policy.md#transport-retries-are-the-inner-loop), because the point of the design is how the two loops relate. In summary:

- **Transport retries** (`laraflow.http.retries`, off) repeat one wire request inside a single step attempt, in milliseconds, for a dropped connection or a momentary 503. They never wait in-process for a `Retry-After` longer than `max_retry_after` (5 s) but return the response, and they repeat a non-idempotent request only on 429 or when the caller marked it `asIdempotent()`.
- **A provider's `Retry-After`** drives the durable step retry: `RateLimitedException` implements `SuggestsRetryDelay`, so a step that throws it is retried when the provider said, up to `laraflow.retry.max_suggested_delay`, with the worker released.
- **Client-side rate limiting** (`laraflow.http.rate_limit`, off) is a ceiling Laraflow holds itself to per connection through Laravel's cache-backed rate limiter, shared by every worker and keyed per connection so one tenant's account never throttles another's. Beyond `max_wait`, the request fails *before reaching the provider* with `ThrottledException`, which suggests the wait the same way.

  Set it on a connection for one account, or under `laraflow.http` for every connection; a connection sets `rate_limit: false` to lift an inherited ceiling it does not share.

  **The ceiling is best effort, not a guarantee.** The check and the count that follows it are two cache operations, not one atomic one, and workers that are waiting out the same window wake together. Under enough concurrency a window can therefore run slightly over. Treat it as keeping ordinary traffic under a provider's limit, not as a quota you can prove to that provider — the provider's own `429` and the durable retry behind it remain the real backstop.

## Pagination

A connector declares a `Paginator` — how its provider says "there is more" — and `pages()` yields the responses, `items()` the items across them. Both are generators: a long result set never exists in memory at once, and a caller that stops early fetches nothing more. Three shapes ship: `LinkHeaderPaginator` (RFC 8288, GitHub), `CursorPaginator` (Slack), `PagePaginator`. A provider whose result key differs per endpoint passes a paginator per call, as Slack's `listing('members')` does.

Five things are **refused rather than tolerated**, all as `PaginationException`:

- **Reaching the page budget** (`max_pages`, 100 by default). This is an error, not a quiet stop: a short list that looks complete is the worst thing a paginator can hand back. Raise the budget deliberately, per call or per connection.
- **A provider repeating a page.** Caught at once rather than looped until the budget.
- **A connector with no paginator** being asked to page.
- **A page budget below one.** Refused when `pages()` is called, before any request: `pages()` is not itself a generator, so a request that cannot be served does not wait for a caller to start iterating to say so. The same is true of asking a connector with no paginator to page.
- **A next-page link to a host the connection never named** — or to any host at all when the connection declares no base URL to check it against. The transport sends an absolute URL as given, and the request carries an `Authorization` header; following it would hand the credential to whoever controls that response. A connector that pages must therefore declare a `baseUrl()`, or its connection must set `base_url`.

A failed page throws rather than ending the iteration quietly. And **do not accumulate pages into a step's output**: a result set large enough to need paging is large enough to meet `laraflow.execution.max_payload_bytes`. Handle each item, or store identifiers.

When paginating, pass query parameters through `options['query']` rather than embedding them in the URI string: Guzzle's `query` option *replaces* a URI query, so a paginator adding `cursor`/`page` to `options['query']` silently drops `?limit=200` written into the URI from page 2 onward. (The bundled connectors use `options['query']` throughout.)

## What a call announces

`ConnectorRequestSent` fires for every response the provider gave. Its `successful` flag reflects the **HTTP status alone**: the event is emitted by the outermost transport layer, before the connector's error mapper runs, so a `200` the mapper rejects (Slack's `ok: false`) is announced as successful — the mapped error lives on the response, not in this event. A listener that must count in-body failures should inspect responses, not this flag. `ConnectorRequestFailed` fires when there was no response at all — and `ConnectorRequestFailed` when there was no response at all. Both carry connector, connection, method, **path without the query string**, duration for the whole call, and wire attempts; neither carries headers, options, or bodies. The failure message is redacted and honours `laraflow.events.include_error_messages`. Neither carries a correlation id, deliberately: a connector may be called outside any workflow, and inside one Laravel's log context already has it.

While a request is in flight, `laraflow.connector` and `laraflow.connection` join the log context, restored afterwards including on failure. `laraflow:connectors` lists what is configured, naming each connection's credentials without printing them; `--check` resolves everything and exits non-zero on a problem, so it can gate a deploy. Details and listener recipes are in [inspection-and-observability.md](inspection-and-observability.md#connector-calls).

## Provider API versions

A connector declares the version it was written against and the header it is sent in; a connection pins another with `api_version`. What that promises — handling the shapes the provider documented for that version, proven against fakes — and what it cannot — that the provider still sends them — is stated in [compatibility.md](compatibility.md#provider-apis).

## Testing a connector

Connector tests run without credentials or network, always: fake HTTP with `Http::fake()`. The package ships the assertions a connector should satisfy as `Laraflow\Testing\ConnectorContract`, so a connector Laraflow does not ship is held to the same standard as the two it does:

```php
(new ConnectorContract)->assertHeldBy(
    app(LaraflowManager::class)->connector('crm'),
    credential: 'secret-token',
    probe: new ConnectorRequest('GET', '/ping'),
);
```

It checks that the connector holds no credential, classifies every error status into the shared types, returns errors rather than throwing them, and keeps both the credential and the query string out of every message. It installs one HTTP fake with stray requests prevented, so a connector that reached the network fails rather than passing quietly; run it in a test that does not install its own `Http::fake()`. Provider-specific behaviour — Slack's `ok: false`, GitHub's rate-limit 403 — is yours to test alongside it.

The harness works by stubbing Laravel's HTTP client, so it can only hold a connector that sends through it. A connector that overrides `transport()` with a client of its own is **refused up front** rather than assessed against requests the harness never saw: `assertHeldBy()` sends one probe first and fails if nothing reached the fake. If your connector has its own transport, fake that transport and assert these same behaviours directly.

The recipe for contributing a connector is in [CONTRIBUTING.md](../CONTRIBUTING.md#contributing-a-connector).

## Operational checklist

- Put credentials under `connections.{name}.credentials`, in `.env`, or behind your own `CredentialProvider`. Never at the connector level; the configuration refuses it.
- Run `laraflow:connectors --check` in your deploy pipeline. It builds every connector and exits non-zero if one cannot be, without sending a request.
- Keep `http.timeout × retries.max_attempts` below any step deadline the request runs under, or the step is classified as timed out while a retry is still waiting.
- Enable a step retry policy before enabling a client-side `rate_limit`. Without one, a throttle turns bursts into failures — honestly, but failures.
- Treat a `PaginationException` on the page budget as a decision, not a nuisance. If the result set is genuinely that large, raise the budget for that call and handle items as they arrive.
- Set `zend.exception_ignore_args=On` in production, and do not expose an exception renderer that displays frame arguments. Laraflow's frames are clean; the HTTP client's beneath them are not Laraflow's to clean.
