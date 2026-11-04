# Triggers: signals over HTTP

> This document was written as the design contract for Milestone 10's triggers before any of it was built, and is now the shipped contract. The enforcing suites are `tests/Feature/Triggers/` and `tests/Unit/Triggers/`, and the install walk drives the endpoint over real HTTP. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.
>
> The configuration and the command this document names are shipped and held to it by the contracts test.

## The problem this solves

A workflow parks on `Wait::forSignal('manager-approval')` and the approval arrives from outside the process: a button in an email, a Slack interaction, a provider's webhook. Today the application writes the controller that receives it — authenticates the caller, parses the body, calls `WorkflowRunner::signal()`, hands the execution to the dispatcher, and maps every refusal the runtime can make onto a response ([advanced-workflows.md](advanced-workflows.md#delays-schedules-and-wait-states)). Every application writes the same controller, and the part they get wrong is the first one: a signal endpoint that trusts its caller is a way for anyone on the network to approve anything.

A trigger is that controller, shipped once: an opt-in route group that verifies a signed request, delivers the signal through the same `signal()` and `WorkflowDispatcher` calls the console and dashboard use, and answers in the runtime's own words. For a provider whose webhooks carry their own signature, the same group mounts a named endpoint behind the provider's verification and the application's mapping of *their* event to *our* execution.

## Shared principles

- **Off unless mounted.** An application that has not called `Triggers::routes()` has no trigger endpoint — no routes, nothing in `route:list`. A test proves the absence, as for the dashboard.
- **Refused unless signed.** Every request to the signed endpoint is verified before anything else is read from the database or written anywhere. There is no `local` environment exception: an endpoint that changes the state of a workflow is not a dashboard, and a bypass that is safe on a laptop is a bypass. Mounting with no key configured refuses *at the mount*, by name, because the alternative is an endpoint that refuses everything and looks like a bug.
- **The application owns the HTTP boundary.** It chooses the path and the middleware (rate limiting above all; `throttle:` is one word in a list). The package ships the routes, the verification, and the delivery; it does not ship an opinion about where the endpoint lives.
- **Nothing the runner cannot do.** A trigger calls `WorkflowRunner::signal()` and then `WorkflowDispatcher::dispatchExecution()`, exactly as the dashboard's signal action does; the outcome is classified by `Handoff` and worded the way the console words it. There is no privileged path, and the reserved `laraflow.` signal prefix is refused at `signal()` as it is for every caller.
- **Payloads are never logged, keys are never persisted.** A refused request is logged with the reason and the target, never the body; the signing key is read from configuration when a request is verified and appears in no record, event, or exception.
- **Idempotent by the signal's own rules.** A duplicate delivery before the execution resumes overwrites the same payload ("last delivery wins"); one that arrives before the step parks is kept in the runtime's signal inbox and answers the park; once the execution has finished, it is refused. The endpoint adds no second idempotency scheme: what the signal contract guarantees ([advanced-workflows.md](advanced-workflows.md#delays-schedules-and-wait-states)) is what the endpoint guarantees.

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
    'middleware' => ['api', 'throttle:60,1'],
    'max_body_bytes' => env('LARAFLOW_TRIGGERS_MAX_BODY_BYTES', 65536),
    'key' => env('LARAFLOW_TRIGGERS_KEY'),
    'previous_keys' => [...],   // LARAFLOW_TRIGGERS_PREVIOUS_KEYS, comma-separated
    'tolerance' => env('LARAFLOW_TRIGGERS_TOLERANCE', 300),
],
```

- **`path`** and **`middleware`** behave as the dashboard's do, with the same call-time overrides: `Triggers::routes(path: 'hooks/laraflow', middleware: [])` for an application mounting inside a group that already supplies its stack. The default middleware is `['api', 'throttle:60,1']`: `api` carries no session — the signature stands in for CSRF protection — and, on Laravel 12/13, no throttle unless the application opted in with `throttleApi()`, so the package's default supplies one: 60 requests a minute per client IP, a `429` past it. Verification is cheap but not free, and an endpoint that changes workflow state deserves a ceiling. A provider that sends bursts from a few addresses needs more: register a named limiter (`RateLimiter::for('laraflow-triggers', …)`) and list `throttle:laraflow-triggers` instead. Overriding `middleware` replaces the whole list, throttle included.
- **`max_body_bytes`** (default 65536) is the largest body read before verification, on the signed endpoint and in front of every named trigger's verifier. A larger body — by its declared `Content-Length` or by what actually arrived — is refused `413 {"status":"too_large"}` before anything is hashed, signed or not, with the reason `body_too_large` on the log and the `TriggerRefused` event. `0` turns the limit off, leaving the web server as the only bound. This is separate from, and checked long before, `execution.max_payload_bytes`.
- **`key`** is the shared secret the signed endpoint verifies against: at least 32 bytes, any encoding, read from the environment and never from a committed file. **`previous_keys`** is a list of keys still accepted, for rotation — read from `LARAFLOW_TRIGGERS_PREVIOUS_KEYS`, comma-separated, so a rotation needs no published configuration file (which also means a key must not contain a comma): set the new key, move the old one there, and remove it once every signer has moved. Every key, previous included, must meet the 32-byte floor — a short key would weaken the endpoint for as long as the rotation lasts — and a request is verified against the current key first and each previous key after, with the match logged at debug so a rotation can be finished with evidence. Use a different key per environment: the signed string carries no audience, so staging and production sharing one key would honour each other's signatures.
- **`tolerance`** is how far, in seconds, a request's timestamp may be from the server's clock in either direction (default 300; a negative value clamps to 0). Past it, the request is refused as stale however well it is signed. Keep it near the default: it is the replay window.

Mounting with `key` blank throws a `LogicException` naming the setting. Mounting twice registers the routes twice, as it would any route group; the dashboard's guidance applies.

## The signed endpoint

```
POST {path}/executions/{id}/signals/{signal}
X-Laraflow-Timestamp: 1762159200
X-Laraflow-Signature: v1=4e5f94e3f18a84cde2bef9b49fd2e03a731fc10a6a8615a85d0642e16a175829
Content-Type: application/json

{"approved_by": "ops@example.com", "note": "Looks right."}
```

(The signature shown is real: this body, this timestamp, execution 42, and the key `an-example-key-32-bytes-long-okay`.)

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

Every verification failure is one answer — `401` with `{"status": "refused"}` and no further detail — so a caller cannot learn from the response whether the key, the timestamp, or the format was the problem, nor whether the execution exists. The log line says which, at `warning`, with the execution id and the signal from the URL — the signal bounded to 255 bytes and stripped of control bytes first, since nothing upstream has validated it — and never the body.

### Replay

A request replayed within `tolerance` verifies again — the signature is valid for as long as the timestamp is. What that can do is bounded by the signal contract: before the execution resumes, the replay overwrites the same payload on the same wait; after it resumes and while the execution is unfinished, the replay is kept in the signal inbox for the next wait on that name, if any; once it has finished, the replay is refused. The window a replay can reach is a **later wait on the same signal name in the same execution** — an approval step that timed out and was retried, re-posting its request, or a second approval under the same name — where an old approval would answer the new wait: within the tolerance by a replay, and at any time by a replay the inbox kept while the execution ran on. The defences, in order of preference: keep `tolerance` short; have the signer include something the step checks in the payload (a request id the step issued when it parked, compared on resume); or put the attempt in the signal name (`approval-2`), which the step chooses when it parks. The endpoint does not keep a table of signatures it has seen, because a store of every inbound request is a second durability contract with its own retention; the signal inbox keeps one row per name, not a history.

### What the endpoint answers

The response is JSON with a `status`, and the HTTP code says which kind of answer it is:

| Code | `status` | When |
|---|---|---|
| `202` | `recorded` | The signal is durable. `delivery` says what became of the execution: `queued` (a worker will resume it), `ran` (the sync driver ran it in this request; `execution_status` carries where it rested — `completed`, or `failed` with the failure on the record), `pending` (recorded, and the push did not land; a fixed `message` says `laraflow:recover` delivers it), `buffered` (the execution is unfinished but not yet parked on that name; the signal is kept in its signal inbox for the step that waits on it, nothing is handed to the queue, and a fixed `message` says so). Nothing a step or a broker threw is ever on the response: a provider keeps response bodies, and the record — where the redactor governs — is where failures live. |
| `401` | `refused` | Verification failed. No other information. |
| `413` | `too_large` | Before verification: the body exceeds `triggers.max_body_bytes`. Said the same whether or not the request was signed. |
| `404` | `not_found` | Signed, and no such execution. Signed first, so an unsigned caller cannot enumerate. |
| `409` | `not_waiting` | Signed, and the execution can never take that signal — finished or compensating, or a reserved name. (A signal for an unfinished execution not yet parked on it is kept in the signal inbox and answered `202` with `delivery: buffered`.) `SignalRefusedException`'s message is the `message`. |
| `413` | `too_large` | Signed, and the payload exceeds `execution.max_payload_bytes`. |
| `422` | `invalid` | The body is not a JSON object, or the signal name is empty, over-long, or reserved. The `message` names the rule. |

Every answer that is *about a signal* also carries `trigger`, `execution` (the id), and `signal`; `message` is the runtime's own wording where the runtime refused. Three answers are about the trigger itself and carry `trigger` alone: `200 {"status":"ignored"}` from a resolver that matched nothing, `404 {"status":"not_found"}` for a name no trigger is registered under (names are slugs, not secrets), and the resolver's `500 {"status":"error"}`. A `5xx` before the `202` usually means the signal was not recorded — and retrying is safe either way, because a duplicate overwrites the same wait, replaces itself in the inbox, or is refused.

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

`register()` is called from a service provider's `boot()`, before or after `routes()` — triggers resolve against the registry at request time; a name may be registered once, must be a URL-safe slug, and may not be `executions` (the signed endpoint's prefix). The signed endpoint is not a named trigger — it addresses executions directly and signs with Laraflow's scheme — and an application needs neither to use the other.

## Signing a request

An application signs its own requests — from the page an approver clicks, from a Slack interaction handler, from a job that relays a provider's event into the signed endpoint — through one helper, so the scheme above is written down once in the package and nowhere else:

```php
use Laraflow\Triggers\TriggerSignature;

$headers = TriggerSignature::sign(executionId: 42, signal: 'manager-approval', body: $json);
// ['X-Laraflow-Timestamp' => '1762159200', 'X-Laraflow-Signature' => 'v1=…']
```

It reads the current key from configuration and the time from Laraflow's clock. The same class is what the endpoint verifies with, so the two cannot drift. For a signer that is not PHP, the scheme is three lines of any language with an HMAC — this is the `openssl` recipe the install walk uses:

```bash
TS=$(date +%s)
BODY='{"approved_by":"ops@example.com"}'
HASH=$(printf '%s' "$BODY" | sha256sum | cut -d' ' -f1)
SIG=$(printf 'v1\n%s\n%s\n%s\n%s' "$TS" "$EXECUTION_ID" "manager-approval" "$HASH" \
      | openssl dgst -sha256 -hmac "$LARAFLOW_TRIGGERS_KEY" | awk '{print $NF}')

curl -X POST "https://app.example.com/laraflow/triggers/executions/$EXECUTION_ID/signals/manager-approval" \
     -H 'Content-Type: application/json' \
     -H "X-Laraflow-Timestamp: $TS" \
     -H "X-Laraflow-Signature: v1=$SIG" \
     --data "$BODY"
```

During a rotation, `LARAFLOW_TRIGGERS_PREVIOUS_KEYS` takes the old keys comma-separated, so a rotation needs no published configuration file.

An approval **page** is the application's. Laraflow does not serve one, and does not accept a signal by `GET`: a link in an email that changes the state of a workflow when a mail client prefetches it is a bug the web learned about long ago. The pattern is a page the application renders that posts to the signed endpoint — or, more simply, a controller action of the application's own that calls `signal()` directly, which has always been available and still is. Triggers exist for the caller that is *not* the application: the provider, the relay, the other service.

## Observability

- **Events.** `TriggerAccepted` after a recorded signal — trigger name (`signed` for the direct endpoint), execution id, signal — and `TriggerRefused` after a refusal — trigger name, the reason as a backed enum (`unsigned`, `body_too_large`, `stale`, `invalid_signature`, `unknown_execution`, `not_waiting`, `invalid_payload`, `too_large`, `resolver_failed`), and the execution id and signal when the URL carried them. No payload, no headers, no key, no client address: an application that wants the address correlates through its own request log, which has it.
- **Logs.** Every refusal at `warning` with the same fields — except a resolver failure, which is the application's own code breaking and logs at `error` with the exception class and never the message, which may quote the body. Every accepted signal logs at `info`, and the existing `WorkflowExecutionSignalled` event fires as it does for any `signal()` call.
- **The dashboard** shows a signalled execution as it shows any other; nothing is added for triggers, because a trigger leaves no record of its own — the signal is the record.

## What this is not

- **Not authentication for the dashboard**, and not a session. A trigger is a machine's request; the signature is its whole identity.
- **Not an inbox.** Inbound requests are not stored, replayed, or listed. The signal they deliver is durable; the request is not, and a webhook archive is an application concern with its own retention.
- **Not a webhook router.** A named trigger resolves to one `SignalIntent` or nothing. An event that should do three things is a workflow, and the trigger signals the step that starts it.
- **Not a way to start a workflow.** Triggers deliver signals to executions that are waiting. Starting one from an inbound request is the application's `start()` or `dispatch()` call in a controller of its own, where the application decides the input.
- **Not a `GET`.** The routes exist for `POST` alone; a `GET` to either path is `405 Method Not Allowed`, the router's own answer, and changes nothing.

## Compatibility notes

- New public surface: `Triggers` (the mount and the registry), `TriggerSignature`, `SignalIntent`, the `TriggerVerifier` contract, `TriggerRefusedException`, the two events, and the refusal-reason enum. The verification middleware and the controller are internal.
- A new `triggers` configuration section — `path`, `middleware` (throttled by default), `max_body_bytes`, `key`, `previous_keys`, `tolerance` — arriving with the step that reads it.
- No schema change. No new repository method: the endpoint reads `find()` and writes through `signal()`.
- `laraflow:signal {execution} {signal} {--payload=} {--deliver}` is the console's form of the same action, sharing the payload parsing and the delivery reporting; it is documented with the console in [public-api.md](public-api.md).

## Decided here

The one-way doors, fixed by this document:

1. Off unless mounted; refused unless signed; no environment bypass; a mount with no key refuses at the mount.
2. The signed string is version, timestamp, execution id, signal name, and body hash — bound to the target, independent of the path.
3. Verification precedes every database read, and every verification failure is the same `401`.
4. No store of seen signatures: replay is bounded by the signal contract and the tolerance, and the residual window is documented with its defences rather than closed with a second durability scheme.
5. Delivery is attempted on every recorded signal and reported in `Handoff`'s terms; the endpoint never answers "recorded" without saying what happened next.
6. Named triggers require a verifier and resolve to one intent or nothing; everything after resolution is the signed endpoint's path.
7. No `GET`, and no approval page: the page is the application's.

## Enforcement

- `tests/Feature/Triggers/MountingTest.php` — unmounted means absent; mounted at the configured path and middleware; refused at the mount with no key or a short one, naming the setting. (The router-absent refusal is guarded in code; its absence cannot be staged under Testbench, which aliases the router class.)
- `tests/Feature/Triggers/SignedEndpointTest.php` — the roadmap's exit criterion: an unsigned request is refused with the execution still waiting and no payload written; a signed one records the payload and resumes the execution (the sync driver runs it to completion in the test, and a queued delivery answers `queued`). Also: a stale timestamp; a wrong key; a previous key during rotation; a signature for another execution presented against this one; a tampered body; each answer in the table above with its code and `status`; `404` only after verification; a failing step's exception text absent from the response; a hostile signal bounded before it is logged; no body in any log line.
- `tests/Feature/Triggers/NamedTriggerTest.php` — the verifier runs first; `null` acknowledges; an intent is delivered through the same path; a throwing resolver is a `500` without the body; a duplicate or reserved name is refused at registration.
- `tests/Unit/Triggers/TriggerSignatureTest.php` — the scheme byte for byte against a fixture, so a signer in another language can be checked against the same vector.
- `.github/actions/install-walk` — over real HTTP against a real application: `curl` unsigned is `401` and the execution stays `waiting`; `curl` signed with `openssl` is `202` and the execution completes.
