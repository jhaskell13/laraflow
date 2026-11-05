# Your first connector

Calling an external service through a connector: the bundled GitHub and Slack connectors, then one of your own. The contract is [connectors.md](connectors.md).

Connectors wrap an external service behind a small, testable contract. Laraflow ships two working examples — GitHub and Slack — deliberately different from each other: GitHub reports failure by status and pages with a `Link` header, Slack reports failure inside a `200` body and pages by cursor. Both are built on the same public contracts your own connector uses, and a test enforces that neither reaches into anything internal.

## Using the bundled GitHub connector

```dotenv
LARAFLOW_GITHUB_TOKEN=ghp_your_token
```

```php
use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\ConnectorRequest;

$response = app(LaraflowManager::class)
    ->connector('github')
    ->request(new ConnectorRequest('GET', '/repos/laravel/framework'));

if ($response->successful()) {
    $stars = $response->body()['stargazers_count'];
}
```

A `ConnectorResponse` keeps the body as received: `body()` decodes it when the server declared JSON and returns the raw string otherwise, `json('path.to.key')` decodes regardless, and `raw()` is the bytes. Failure to reach the provider at all throws `Laraflow\Exceptions\TransportException`.

An error *response* is returned, not thrown, with its exception attached. Every connector maps responses to typed exceptions — `AuthenticationFailedException`, `NotFoundException`, `RateLimitedException`, `ClientErrorException`, `ServerErrorException`, all under `Laraflow\Exceptions\Connector` — by status, or by whatever the provider's convention is. Inspect or raise as you prefer:

```php
$response = $github->request(new ConnectorRequest('GET', '/repos/acme/app'));

if ($response->failed()) {
    $response->error();  // a NotFoundException, with ->response attached
}

$repo = $github->request(new ConnectorRequest('GET', '/repos/acme/app'))->throw()->json();
```

Setting `throw_on_error` on a connection makes `request()` throw instead. Exception messages name the connection, method, path, status, and the provider's own explanation — never the query string or request options, which may carry credentials.

A connector serves any number of **connections** — one per account. Add a second entry under `connections` and name it when resolving; the same class serves both:

```php
'connections' => [
    'default' => ['credentials' => ['token' => env('LARAFLOW_GITHUB_TOKEN')]],
    'enterprise' => ['credentials' => ['token' => env('GHE_TOKEN')]],
],
```

```php
$laraflow->connector('github', 'enterprise')->request(...);
```

Credentials are resolved by a `CredentialProvider` **when each request is built**, not when the connector is constructed. Rotating a token means changing what the provider returns — the next request uses it, nothing is rebuilt — and the connector object itself never holds one. The default provider reads the `credentials` array above; bind your own to read a vault or a per-tenant store:

```php
$this->app->singleton(CredentialProvider::class, VaultCredentialProvider::class);
```

Every credential is a `Laraflow\Support\Secret`: it prints as `[redacted]` wherever PHP would print it — logs, exception pages, `var_dump`, JSON, serialization — and gives up its value only to `reveal()`.

## Using the bundled Slack connector

```dotenv
LARAFLOW_SLACK_TOKEN=xoxb-your-bot-token
```

```php
$slack = app(LaraflowManager::class)->connector('slack');

$slack->request($slack->write('chat.postMessage', [
    'channel' => 'C0123456789',
    'text' => 'Order shipped.',
]))->throw();

foreach ($slack->items($slack->read('users.list'), paginator: $slack->listing('members')) as $member) {
    // one page at a time, as many as the page budget allows
}
```

Slack answers `200 OK` with `{"ok": false, "error": "channel_not_found"}` for most failures. The connector's error mapper turns those into the same exception types every other connector produces, so `NotFoundException` means the same thing whichever provider raised it.

## Writing your own

Extend `Laraflow\Integration\HttpConnector` and describe the provider: where it lives, which headers it wants, how it authenticates. The base class resolves credentials for each request, authenticates, and sends through the configured transport:

```php
<?php

namespace App\Integrations;

use Laraflow\Auth\Authenticator;
use Laraflow\Auth\BearerTokenAuthenticator;
use Laraflow\Integration\HttpConnector;

class CrmConnector extends HttpConnector
{
    protected function baseUrl(): ?string
    {
        return 'https://crm.example/api/v2';
    }

    protected function headers(): array
    {
        return ['Accept' => 'application/json'];
    }

    protected function authenticator(): Authenticator
    {
        return new BearerTokenAuthenticator('token', 'CRM_TOKEN');
    }
}
```

Register it by naming the class in configuration. It is built through the container with its `Connection` passed as `$connection` and everything else autowired, on first use, once per connection. A connection may override the class's `base_url`, `timeout`, `connect_timeout`, and `headers` — a sandbox host for one account, a slower region for another:

```php
// config/laraflow.php
'integrations' => [
    'crm' => [
        'connector' => \App\Integrations\CrmConnector::class,
        'connections' => [
            'default' => [
                'credentials' => ['token' => env('CRM_TOKEN')],
            ],
            'sandbox' => [
                'base_url' => 'https://sandbox.crm.example/api/v2',
                'timeout' => 60,
                'credentials' => ['token' => env('CRM_SANDBOX_TOKEN')],
            ],
        ],
    ],
],
```

A package registers from its own service provider instead, with the class or a closure receiving the `Connection`:

```php
app(LaraflowManager::class)->register('crm', CrmConnector::class);
```

A connection can enable **transport retries** — repeating one request inside a step attempt for a dropped connection or a momentary 503 — with `'retries' => ['max_attempts' => 3]`. They are off by default, measured in milliseconds, and never wait for a long `Retry-After`: that is returned to the step so its own retry policy can wait durably, without holding a worker. A `POST` is repeated only when the provider says it was never processed (429) or you mark the request `->asIdempotent()`. The two loops are specified together in [retry-and-failure-policy.md](retry-and-failure-policy.md#transport-retries-are-the-inner-loop).

**Rate limits** work in both directions. A provider's `429` with `Retry-After` becomes a `RateLimitedException`, and because it implements `SuggestsRetryDelay`, a step that throws it is retried durably *when the provider said* — up to `retry.max_suggested_delay` — rather than on the policy's guess. And a connection's own `'rate_limit' => ['max' => 60, 'per' => 60]` is a ceiling Laraflow holds itself to, shared by every worker through the cache, keyed per connection so one tenant's account never throttles another's — or, with `'scope' => 'tenant'`, per tenant of the running execution, so one tenant's burst cannot spend a shared account's ceiling; when the budget is spent the request fails before reaching the provider with `ThrottledException`, which suggests the wait the same way.

**Paging** is lazy and bounded. Declare a `paginator()` on the connector and call `pages()` for responses or `items()` for the items across them; both are generators, so a long result set never exists in memory at once and stopping early fetches nothing more:

```php
foreach ($github->items(new ConnectorRequest('GET', '/repos')) as $repo) {
    Repository::sync($repo);   // handle each one; do not collect them
}
```

Three shapes ship: `LinkHeaderPaginator` (GitHub), `CursorPaginator` (Slack), and `PagePaginator`. Reaching the page budget — `max_pages` on the connection, or `laraflow.http.pagination.max_pages` — raises `PaginationException` rather than stopping quietly, because a short list that looks complete is worse than an error. A next-page link pointing at a host the connection never named is refused too: the request carries your credentials.

Do not accumulate pages into a step's output. A result set big enough to need paging is big enough to meet `execution.max_payload_bytes`; handle each item as it arrives, or store identifiers.

A connector declares the **provider API version** it was written against and sends it where the provider wants it — GitHub's `X-GitHub-Api-Version: 2022-11-28` — and a connection may pin another with `api_version`, per account. What that promises, and what it cannot, is in [compatibility.md](compatibility.md#provider-apis).

Every call announces itself: `ConnectorRequestSent` for any response the provider gave, `ConnectorRequestFailed` when there was none. Both carry the connector, connection, method, path, duration, and wire attempts — plus the status and the connector's success verdict on `Sent`, the exception class and redacted message on `Failed` — and never headers or bodies, with the path deliberately stripped of its query string. While a request is in flight, `laraflow.connector` and `laraflow.connection` join Laravel's log context. `php artisan laraflow:connectors` lists what is configured, naming credentials without printing them; `--check` resolves everything and exits non-zero on a problem, so it can gate a deploy.

Override `errorMapper()` when the provider has its own way of saying no — Slack answers `200` with `"ok": false`; GitHub reports an exhausted rate limit as `403`. Extend `StatusErrorMapper`, handle the special case, and fall back to `parent::map()`.

Requests go through an `HttpTransport`, Laravel's HTTP client by default — so `Http::fake()` works in your tests and the client's middleware applies. Bind another implementation to replace it for every connector, or override `transport()` on one. A connector that is not HTTP at all implements `Laraflow\Integration\Connector` directly: `name()` and `request()`.

Steps can then type-hint `LaraflowManager` and call `->connector('crm')`.

Hold your connector to the same contract the bundled ones meet. `Laraflow\Testing\ConnectorContract` ships with the package and checks what is easy to get subtly wrong — that the connector holds no credential, classifies every error status into the shared exception types, returns errors rather than throwing them, and keeps the credential and query string out of every message:

```php
(new ConnectorContract)->assertHeldBy(
    app(LaraflowManager::class)->connector('crm'),
    credential: 'secret-token',
    probe: new ConnectorRequest('GET', '/ping'),
);
```

It fakes HTTP and prevents stray requests, so it needs no credentials and no network. The full recipe is in [CONTRIBUTING.md](../CONTRIBUTING.md#contributing-a-connector).
