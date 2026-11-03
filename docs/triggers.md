# Triggers: signals over HTTP

> This document is the design contract for Milestone 10's triggers, written before any of it is built. The enforcing suites will be `tests/Feature/Triggers/`, and the install walk will drive the endpoint over real HTTP. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.
>
> Planned commands are named by verb here and in full in [public-api.md](public-api.md) as each ships; planned configuration keys are written without the `laraflow.` prefix for the same reason.

## The problem this solves

A workflow parks on `Wait::forSignal('manager-approval')` and the approval arrives from outside the process: a button in an email, a Slack interaction, a provider's webhook. Today the application writes the controller that receives it — authenticates the caller, parses the body, calls `WorkflowRunner::signal()`, hands the execution to the dispatcher, and maps every refusal the runtime can make onto a response ([advanced-workflows.md](advanced-workflows.md#delays-schedules-and-wait-states)). Every application writes the same controller, and the part they get wrong is the first one: a signal endpoint that trusts its caller is a way for anyone on the network to approve anything.

A trigger is that controller, shipped once: an opt-in route group that verifies a signed request, delivers the signal through the same `signal()` and `WorkflowDispatcher` calls the console and dashboard use, and answers in the runtime's own words. For a provider whose webhooks carry their own signature, the same group mounts a named endpoint behind the provider's verification and the application's mapping of *their* event to *our* execution.

## Shared principles

- **Off unless mounted.** An application that has not called `Triggers::routes()` has no trigger endpoint — no routes, nothing in `route:list`. A test proves the absence, as for the dashboard.
- **Refused unless signed.** Every request to the signed endpoint is verified before anything else is read from the database or written anywhere. There is no `local` environment exception: an endpoint that changes the state of a workflow is not a dashboard, and a bypass that is safe on a laptop is a bypass. Mounting with no key configured refuses *at the mount*, by name, because the alternative is an endpoint that refuses everything and looks like a bug.
- **The application owns the HTTP boundary.** It chooses the path and the middleware (rate limiting above all; `throttle:` is one word in a list). The package ships the routes, the verification, and the delivery; it does not ship an opinion about where the endpoint lives.
- **Nothing the runner cannot do.** A trigger calls `WorkflowRunner::signal()` and then `WorkflowDispatcher::dispatchExecution()`, exactly as the dashboard's signal action does; the outcome is classified by `Handoff` and worded the way the console words it. There is no privileged path, and the reserved `laraflow.` signal prefix is refused at `signal()` as it is for every caller.
- **Payloads are never logged, keys are never persisted.** A refused request is logged with the reason and the target, never the body; the signing key is read from configuration when a request is verified and appears in no record, event, or exception.
- **Idempotent by the signal's own rules.** A duplicate delivery before the execution resumes overwrites the same payload ("last delivery wins"); after it resumes, it is refused. The endpoint adds no inbox table and no second idempotency scheme: what the signal contract already guarantees is what the endpoint guarantees.

## Dependencies

The trigger routes need `illuminate/routing`, which the package suggests rather than requires, for the reason [dashboard.md](dashboard.md#dependencies) gives. `Triggers::routes()` refuses by name when the router is not bound. Unlike the dashboard, triggers need neither views nor a session: a trigger is an API, answered in JSON, and the thing that stands in for CSRF protection is the signature.

## Mounting

```php
// routes/api.php, or the boot() of a service provider
use Laraflow\Triggers\Triggers;

Triggers::routes();
```

```php
// config/laraflow.php
'triggers' => [
    'path' => env('LARAFLOW_TRIGGERS_PATH', 'laraflow/triggers'),
    'middleware' => ['api'],
    'key' => env('LARAFLOW_TRIGGERS_KEY'),
    'previous_keys' => [],
    'tolerance' => env('LARAFLOW_TRIGGERS_TOLERANCE', 300),
],
```

- **`path`** and **`middleware`** behave as the dashboard's do, with the same call-time overrides: `Triggers::routes(path: 'hooks/laraflow', middleware: [])` for an application mounting inside a group that already supplies its stack. The default middleware is `api`, which in a Laravel application carries request throttling and no session; an application that wants a tighter rate limit on this endpoint than on its API puts one here.
- **`key`** is the shared secret the signed endpoint verifies against: at least 32 bytes, any encoding, read from the environment and never from a committed file. **`previous_keys`** is a list of keys still accepted, for rotation: set the new key, move the old one here, and remove it once every signer has moved. A request is verified against the current key first and each previous key after, and which one matched is logged at debug so a rotation can be finished with evidence.
- **`tolerance`** is how far, in seconds, a request's timestamp may be from the server's clock in either direction (default 300). Past it, the request is refused as stale however well it is signed.

Mounting with `key` blank throws a `LogicException` naming the setting. Mounting twice registers the routes twice, as it would any route group; the dashboard's guidance applies.

## The signed endpoint

```
POST {path}/executions/{id}/signals/{signal}
X-Laraflow-Timestamp: 1762159200
X-Laraflow-Signature: v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8c2
Content-Type: application/json

{"approved_by": "ops@example.com", "note": "Looks right."}
```

- **`{id}`** is the execution's integer id (anything else is not a route, as on the dashboard). **`{signal}`** is the signal name, URL-encoded; the usual rules apply at `signal()` — non-empty, at most 255 characters, not beginning with `laraflow.` in any casing.
- **The body** is the payload: a JSON object, or empty for a signal that carries nothing. A JSON list, a scalar, or malformed JSON is refused, exactly as the dashboard's payload field and the start command's `--input` refuse them, and for the same reason: the payload lands in the context under the signal's name, and the context is an object. The body is bounded by `execution.max_payload_bytes` at `signal()`, as every signal payload is; the application's web server bounds it before that.

### The signature

The signed string binds the request to its target and its moment:

```
v1 "\n" timestamp "\n" execution id "\n" signal name "\n" lowercase hex SHA-256 of the raw body
```

and the signature is `HMAC-SHA256(key, signed string)` as lowercase hex, sent as `v1=<hex>`. The timestamp is Unix seconds and is sent in its own header so the verifier reads the same bytes the signer signed. The path is deliberately **not** in the signed string — a reverse proxy that adds or strips a prefix would otherwise break every signer — and the execution id and signal name *are*, so a signature issued for one execution's approval cannot be presented for another's, whatever the body. An empty body hashes as the SHA-256 of the empty string.

Verification, in order, before any database read:

1. Both headers present and well-formed; the timestamp an integer within `tolerance` of the server clock.
2. The signature compared in constant time against the HMAC under the current key, then each previous key.
3. Only then: the execution looked up, the signal recorded, the delivery attempted.

Every verification failure is one answer — `401` with `{"status": "refused"}` and no further detail — so a caller cannot learn from the response whether the key, the timestamp, or the format was the problem, nor whether the execution exists. The log line says which, at `warning`, with the execution id and signal from the URL and never the body.

### Replay

A request replayed within `tolerance` verifies again — the signature is valid for as long as the timestamp is. What that can do is bounded by the signal contract: before the execution resumes, the replay overwrites the same payload on the same wait; after it resumes, the replay is refused because nothing is waiting on that name. The one window a replay can reach is an execution that resumed and **re-parked on the same signal name within the tolerance** — an approval step that timed out and was retried inside five minutes, re-posting its request — where an old approval would answer the new wait. The defences, in order of preference: keep `tolerance` short; have the signer include something the step checks in the payload (a request id the step issued when it parked, compared on resume); or put the attempt in the signal name (`approval-2`), which the step chooses when it parks. The endpoint does not keep a table of signatures it has seen, because a store of every inbound request is an inbox, and an inbox is a second durability contract with its own retention — the signal already has one.

### What the endpoint answers

The response is JSON with a `status`, and the HTTP code says which kind of answer it is:

| Code | `status` | When |
|---|---|---|
| `202` | `recorded` | The signal is durable. `delivery` says what became of the execution, in `Handoff`'s terms: `queued` (a worker will resume it), `ran` (the sync driver, or the execution rested without needing a worker — its resulting `execution.status` is included), `pending` (recorded, but the push failed; `laraflow:recover` delivers it, and the message says so). |
| `401` | `refused` | Verification failed. No other information. |
| `404` | `not_found` | Signed, and no such execution. Signed first, so an unsigned caller cannot enumerate. |
| `409` | `not_waiting` | Signed, and the execution is not waiting on that signal — resumed already, timed out, finished, or parked on another name. `SignalRefusedException`'s message is the `message`. |
| `413` | `too_large` | The payload exceeds `execution.max_payload_bytes`. |
| `422` | `invalid` | The body is not a JSON object, or the signal name is empty, over-long, or reserved. The `message` names the rule. |

Every non-`401` answer carries `execution` (the id) and `signal`; `message` is the runtime's own wording where the runtime refused. A `5xx` is what it is anywhere: the database was down, and the signal was not recorded.

## Named triggers for providers

A provider's webhook is not addressed to an execution: Stripe says a payment intent succeeded and the application knows which execution was waiting for it. The provider signs its request its own way, and the application already knows how to verify it. A **named trigger** mounts an endpoint for that case:

```php
use Laraflow\Triggers\Triggers;
use Laraflow\Triggers\SignalIntent;

Triggers::register('stripe', StripeWebhookVerifier::class, function (Request $request): ?SignalIntent {
    $event = $request->json();

    if ($event['type'] !== 'payment_intent.succeeded') {
        return null; // acknowledged, nothing to signal
    }

    $executionId = Order::where('payment_intent', $event['data']['object']['id'])->value('workflow_execution_id');

    return $executionId === null ? null : new SignalIntent($executionId, 'payment-confirmed', [
        'amount' => $event['data']['object']['amount_received'],
    ]);
});
```

```
POST {path}/{name}
```

- **The verifier** is a class implementing `TriggerVerifier` — `verify(Request $request): void`, throwing `TriggerRefusedException` to refuse — resolved from the container so it can take the provider's secret from wherever the application keeps it. Verification runs first, exactly as for the signed endpoint, and a refusal is the same `401 refused`. A named trigger with no verifier is not registerable: the name of the method is the point.
- **The resolver** turns the verified request into a `SignalIntent` — execution id, signal name, payload — or `null` to acknowledge an event that signals nothing (`200 {"status": "ignored"}`), which is most of what a webhook endpoint receives. A resolver that throws is a `500`, logged with the trigger's name and never the body: the application's mapping failed, and the provider should retry.
- **Everything after resolution is the signed endpoint's path**: the same `signal()`, the same delivery, the same answers. A provider that retries on non-`2xx` gets `202` for a recorded signal and `200` for an ignored event, and `409` when the execution has moved on — which is the right answer for an event that arrived late and the one a provider stops retrying.

`register()` is called before `routes()`, from a service provider's `boot()`; a name may be registered once, must be a URL-safe slug, and may not be `executions` (the signed endpoint's prefix). The signed endpoint is not a named trigger — it addresses executions directly and signs with Laraflow's scheme — and an application needs neither to use the other.

## Signing a request

An application signs its own requests — from the page an approver clicks, from a Slack interaction handler, from a job that relays a provider's event into the signed endpoint — through one helper, so the scheme above is written down once in the package and nowhere else:

```php
use Laraflow\Triggers\TriggerSignature;

$headers = TriggerSignature::sign(executionId: 42, signal: 'manager-approval', body: $json);
// ['X-Laraflow-Timestamp' => '1762159200', 'X-Laraflow-Signature' => 'v1=…']
```

It reads the current key from configuration and the time from Laraflow's clock. The same class is what the endpoint verifies with, so the two cannot drift. For a signer that is not PHP, the scheme is three lines of any language with an HMAC, and the documentation that ships with the step gives the `openssl` recipe the install walk uses.

An approval **page** is the application's. Laraflow does not serve one, and does not accept a signal by `GET`: a link in an email that changes the state of a workflow when a mail client prefetches it is a bug the web learned about long ago. The pattern is a page the application renders that posts to the signed endpoint — or, more simply, a controller action of the application's own that calls `signal()` directly, which has always been available and still is. Triggers exist for the caller that is *not* the application: the provider, the relay, the other service.

## Observability

- **Events.** `TriggerAccepted` after a recorded signal — trigger name (`signed` for the direct endpoint), execution id, signal — and `TriggerRefused` after a refusal — trigger name, the reason as a backed enum (`unsigned`, `stale`, `invalid_signature`, `unknown_execution`, `not_waiting`, `invalid_payload`, `too_large`, `resolver_failed`), and the execution id and signal when the URL carried them. No payload, no headers, no key, no client address: an application that wants the address correlates through its own request log, which has it.
- **Logs.** Every refusal at `warning` with the same fields; every accepted signal at `info`. The existing `WorkflowExecutionSignalled` event fires as it does for any `signal()` call.
- **The dashboard** shows a signalled execution as it shows any other; nothing is added for triggers, because a trigger leaves no record of its own — the signal is the record.

## What this is not

- **Not authentication for the dashboard**, and not a session. A trigger is a machine's request; the signature is its whole identity.
- **Not an inbox.** Inbound requests are not stored, replayed, or listed. The signal they deliver is durable; the request is not, and a webhook archive is an application concern with its own retention.
- **Not a webhook router.** A named trigger resolves to one `SignalIntent` or nothing. An event that should do three things is a workflow, and the trigger signals the step that starts it.
- **Not a way to start a workflow.** Triggers deliver signals to executions that are waiting. Starting one from an inbound request is the application's `start()` or `dispatch()` call in a controller of its own, where the application decides the input.
- **Not a `GET`.** State changes on a `GET` are refused as a route that does not exist.

## Compatibility notes

- New public surface: `Triggers` (the mount and the registry), `TriggerSignature`, `SignalIntent`, the `TriggerVerifier` contract, `TriggerRefusedException`, the two events, and the refusal-reason enum. The verification middleware and the controller are internal.
- A new `triggers` configuration section — `path`, `middleware`, `key`, `previous_keys`, `tolerance` — arriving with the step that reads it.
- No schema change. No new repository method: the endpoint reads `find()` and writes through `signal()`.
- A new command, `signal` by verb, is the console's form of the same action and is specified with the operator ergonomics rather than here; it shares the payload parsing and the delivery reporting.

## Decided here

The one-way doors, fixed by this document:

1. Off unless mounted; refused unless signed; no environment bypass; a mount with no key refuses at the mount.
2. The signed string is version, timestamp, execution id, signal name, and body hash — bound to the target, independent of the path.
3. Verification precedes every database read, and every verification failure is the same `401`.
4. No inbox and no signature store: replay is bounded by the signal contract and the tolerance, and the residual window is documented with its defences rather than closed with a second durability scheme.
5. Delivery is attempted on every recorded signal and reported in `Handoff`'s terms; the endpoint never answers "recorded" without saying what happened next.
6. Named triggers require a verifier and resolve to one intent or nothing; everything after resolution is the signed endpoint's path.
7. No `GET`, and no approval page: the page is the application's.

## Enforcement

- `tests/Feature/Triggers/MountingTest.php` — unmounted means absent; mounted at the configured path and middleware; refused at the mount with no key, naming the setting; refused by name without the router.
- `tests/Feature/Triggers/SignedEndpointTest.php` — the roadmap's exit criterion: an unsigned request is refused with the execution still waiting and no payload written; a signed one records the payload and resumes the execution (the sync driver runs it to completion in the test). Also: a stale timestamp; a wrong key; a previous key during rotation; a signature for another execution presented against this one; a tampered body; each answer in the table above with its code and `status`; `404` only after verification; no body in any log line.
- `tests/Feature/Triggers/NamedTriggerTest.php` — the verifier runs first; `null` acknowledges; an intent is delivered through the same path; a throwing resolver is a `500` without the body; a duplicate or reserved name is refused at registration.
- `tests/Unit/Triggers/TriggerSignatureTest.php` — the scheme byte for byte against a fixture, so a signer in another language can be checked against the same vector.
- `.github/actions/install-walk` — over real HTTP against a real application: `curl` unsigned is `401` and the execution stays `waiting`; `curl` signed with `openssl` is `202` and the execution completes.
