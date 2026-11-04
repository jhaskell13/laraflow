# The inspection dashboard

> This document was written as the design contract for Milestone 8.5's dashboard before any of it was built. The enforcing suite is `tests/Feature/Dashboard/`. The repository's usual rule applies: if the code and this document disagree, one of them has a bug.

## What it is

The console already answers "show me": `laraflow:list`, `laraflow:show`, and `laraflow:summary` run on the public `ExecutionRepository` contract alone, and `tests/Feature/ProjectContractsTest.php` reads their source to keep them there ([inspection-and-observability.md](inspection-and-observability.md), *The principle*). **The dashboard is that same surface rendered in a browser.** It is not a new view of the data; it is the same view with a different renderer, and the same test holds it to the same contract.

That sentence decides most of what follows. A page that needs something the contract cannot answer does without it, or the contract gains the method deliberately — with its index — as a change to the public API, never as a private shortcut for the dashboard.

## Shared principles

- **Off unless mounted.** An application that has not called `Dashboard::routes()` has no dashboard: no routes, no assets, no queries, nothing in `route:list`. A test proves the absence.
- **Denied unless allowed.** Once mounted, every route — including the stylesheet — passes through one authorization check. The default allows the `local` environment and nothing else. A dashboard that is open by default is a data leak with a nice font.
- **The application owns the HTTP boundary.** It chooses the path, the middleware (session, CSRF, authentication), and the authorization rule. The package ships the pages and the check; it does not ship an opinion about how your users log in.
- **Nothing the console cannot do.** Every row and number is obtainable from `ExecutionRepository` and `IdempotencyStore`; every action goes through the same `WorkflowRunner` and `WorkflowDispatcher` methods the console commands call. The dashboard has no privileged path.
- **Redaction carries over.** `laraflow:connectors` never prints a credential and `laraflow:show --attempts` never prints a remembered value. The dashboard inherits both rules.
- **Works without JavaScript.** Every page is a full server render and every action is a `<form>`. Script is progressive enhancement — refresh, confirmation — and a browser without it sees the same data and can take the same actions.
- **No build step, anywhere.** The stylesheet is one hand-written CSS file committed to the repository and served by the package. Installing the dashboard adds nothing to an application's `package.json`, requires no `npm`, and reaches no CDN — an operator's tool has to work behind a firewall.
- **Inspects, does not author.** The dashboard shows what ran and lets an operator move it along. It does not edit a workflow, a step, or a plan. The one action that creates something, *start*, runs an existing class ([ROADMAP.md](../ROADMAP.md), *Out of scope through 1.0*).

## Dependencies

The dashboard needs `illuminate/routing`, `illuminate/view`, and — for the action forms' CSRF protection — `illuminate/session`. The package does **not** require them. They are listed under `suggest` in `composer.json`, and `Dashboard::routes()` refuses with a message naming them when the router or the view factory is not bound.

Every `laravel/framework` application has all three, so an application installing Laraflow the ordinary way notices nothing. The distinction matters for the slim case the rest of the package already honours: a container that resolves the runner without the HTTP layer keeps its install footprint, exactly as `LoggingContext` degrades when Laravel's context repository is not bound. The dashboard is opt-in at every layer — the dependencies, the mount, the authorization, the actions — and forcing any one of them would make it a tax on applications that only want the runtime.

## Mounting

```php
// routes/web.php, or the boot() of a service provider
use Laraflow\Dashboard\Dashboard;

Dashboard::routes();
```

That registers the dashboard under the configured path and middleware:

```php
// config/laraflow.php
'dashboard' => [
    // Where the dashboard answers. The default mounts it at /laraflow.
    'path' => env('LARAFLOW_DASHBOARD_PATH', 'laraflow'),

    // The middleware every dashboard route runs through, before the
    // dashboard's own authorization check. `web` supplies the session
    // and CSRF protection the action forms depend on.
    'middleware' => ['web'],

    // Mount a dashboard that can only look. Every action route is
    // absent — not hidden — so there is nothing to find.
    'read_only' => env('LARAFLOW_DASHBOARD_READ_ONLY', false),

    // A gate the actions must also pass, on top of the view rule.
    // Null: whoever may view may act. See "Authorization".
    'action_gate' => env('LARAFLOW_DASHBOARD_ACTION_GATE'),

    // How many executions a list page shows, up to the repository's
    // own ceiling.
    'per_page' => env('LARAFLOW_DASHBOARD_PER_PAGE', 25),

    // Seconds between a page asking for itself again; 0 turns it off.
    'refresh' => env('LARAFLOW_DASHBOARD_REFRESH', 10),
],
```

`Dashboard::routes()` accepts overrides for the path and middleware when an application mounts it somewhere configuration cannot express — inside an existing admin group, say:

```php
Route::middleware(['web', 'auth', 'admin'])->prefix('ops')->group(function () {
    Dashboard::routes(path: 'workflows', middleware: []);
});
```

The path is where the dashboard *answers*; nothing else about the application moves. With the default, the pages are `/laraflow`, `/laraflow/executions`, `/laraflow/executions/{id}`, and the stylesheet `/laraflow/assets/dashboard.css`.

## Authorization

One check, applied to every mounted route by the `Authorize` middleware:

```php
// AppServiceProvider::boot()
use Laraflow\Dashboard\Dashboard;

Dashboard::auth(fn ($request) => $request->user()?->can('operate-workflows') ?? false);
```

The callback receives the request and answers yes or no. Without one, the default rule is:

1. the application is running in the `local` environment, **or**
2. the application has defined a `viewLaraflow` gate and it allows the request's user.

Anything else is a `403`. The default is deliberately narrow: a dashboard mounted in production without an explicit rule denies everyone, which is the failure mode that gets noticed and fixed.

### Who may act

By default the view rule is also the action rule: an operator who may see a failed execution is the operator who recovers it, and an application that never configures the split behaves exactly as before. To let some viewers look but not touch, give the four actions a second rule, checked by the `AuthorizeAction` middleware on the `POST` routes alone:

```php
// Either a callback…
Dashboard::authActions(fn ($request) => $request->user()?->can('operate-workflows') ?? false);

// …or a gate named in configuration.
Gate::define('actOnLaraflow', fn ($user) => $user->isOncall());
// config/laraflow.php: 'dashboard' => ['action_gate' => 'actOnLaraflow', ...]
```

The callback wins over the gate. The action rule *narrows* the view rule — it is asked only of a request the view rule has already admitted, so it cannot let in anyone `Dashboard::auth()` or `viewLaraflow` refused. A refused action is a `403`, and a viewer who may not act is not offered the forms. A gate named in `action_gate` that the application never defines denies every action rather than falling back to the view rule: a name in configuration says acting is restricted, and a typo must not quietly undo that. Mounting a read-only dashboard for one audience and a full one for another remains an option.

## Pages

| Page | Reads | Shows |
|---|---|---|
| **Overview** | `count()` per status; the attention scan `laraflow:summary` runs | Counts by status; retries and waits due; running steps past their deadline; stalled schedules; executions a tenant ceiling is holding; the oldest unfinished execution |
| **Executions** | `list()`, `count()`, `stepProgress()` | A filterable page of executions: id, workflow, status, step progress, age, correlation, tenant, duration |
| **Execution** | `find()`, `steps()`, `attemptHistory()`, `IdempotencyStore::records()`, `ScheduleRepository::tickForExecution()` | Everything `laraflow:show --attempts` prints: the record, its steps, each step's attempts, what it is waiting on, its plan version, its parent or children, the schedule tick that started it, a deferral note when a tenant ceiling is holding it, and the keys it has remembered — never their values |

The overview's "what needs attention" is the same computation `laraflow:summary` performs. It is extracted from the command into a service both call, so the console and the browser cannot disagree about what is wrong.

### Filtering

The filter form filters as it is typed. With script, a change loads the filtered page **in place** — the same fetch-and-swap the refresh uses, with the address pushed onto history so *back* works — rather than navigating, because a navigation would take the field out from under the operator mid-word and lose the caret. Text fields are debounced; a checkbox is a decision and applies at once. Without script the form is an ordinary `GET` with a *Filter* button, which the stylesheet hides when script is running rather than the page omitting it, so the form still works if the script fails to load. Paging and *Clear* load in place too, keeping scroll position and open disclosures.

The executions page filters by exactly what `ExecutionFilter` offers — status, workflow, correlation id, tenant id, and a creation window — because those are the criteria the schema serves from an index, and an unindexed filter on a high-volume table is how observability tooling takes an application down ([inspection-and-observability.md](inspection-and-observability.md), *Filtering*). The filter form's workflow field is free text, as `laraflow:list --workflow=` is: the contract has no method that lists distinct workflow classes, and one would be a scan over the table. The field suggests the workflows visible on the current page. If that proves insufficient, the method is added to `ExecutionRepository` as a public change with its own index justification, not slipped in for the dashboard.

A repeatable filter takes at most twenty values and a pagination trail at most fifty cursors; past that the extras are dropped and the page says so. A filter is typed by a person, but a crafted URL is not, and each workflow named in a filter becomes two more scans on the overview.

### Pagination

`ExecutionRepository::list()` paginates by keyset: a cursor names the last row of a page and the next page starts strictly after it. That is stable under concurrent inserts and costs the same on page 1000 as on page 1, and it is one-directional on purpose. The dashboard offers *next* and *first*; *previous* works by carrying the cursors already visited in the query string, so stepping back replays a cursor the repository issued rather than inventing an offset. There is no "page 7 of 40" — a count of pages over a table being written to would be wrong before it was rendered.

## Actions

Four, each the browser form of a console command, each through the same service:

| Action | Console equivalent | Service call |
|---|---|---|
| Execute / recover | `laraflow:execute {id}` and `--now` | `WorkflowRunner::execute()`, or `WorkflowDispatcher::dispatchExecution()` when queued |
| Cancel | `laraflow:cancel {id} --reason=` | `WorkflowRunner::cancel()` |
| Signal | `laraflow:signal` | `WorkflowRunner::signal()`, then the shared queue handoff (`WorkflowDispatcher::dispatchExecution()`) when asked to deliver |
| Start | `laraflow:start {workflow} --input=` | the same resolution and validation, then `WorkflowRunner` or `WorkflowDispatcher` |

Every action is a `POST` with CSRF protection and passes the same authorization check as every page, then the action rule above. The CSRF check is the application's middleware (`web`) *and* the dashboard's own: the action routes refuse a request with no session (a `500` naming the misconfiguration), and compare the request's `_token` field — or its `X-CSRF-TOKEN` header — to the session's token with `hash_equals`, answering `419` on a mismatch. Behind `web` that is the same comparison twice; behind a custom stack that starts a session but omits `VerifyCsrfToken`, it is the only one. Like Laravel's own middleware it is skipped while running unit tests. The encrypted `X-XSRF-TOKEN` header is not accepted; the dashboard's own forms all carry `@csrf`. Confirmation is two-layered: with script, a dialog before the post; without, each action sits behind a disclosure and its own labelled button, so nothing happens on a stray click either way. The page after an action is the execution's detail page, showing the record as it now stands; the outcome is reported the way the console reports it, from the same `Outcome` definition — a wait as a wait, a retry as a retry, never a parked execution as "done". Whether a dispatch ran the job in the request (a `sync` connection does) is judged from the record, not from configuration, for the reason the Phase C audit recorded — every dispatch, the delivered signal's included, goes through the same handoff the console uses, so a step that runs and fails inline on a `sync` connection is reported as a step failure, not as a failed dispatch.

A step's exception reaches the flashed outcome the way it reaches the execution row: through the configured `ErrorRedactor` (`laraflow.errors.redactor`) and truncated to `laraflow.execution.max_error_length`. The flash is rendered to a browser and held in the session, so it gets nothing the record would not.

*Execute* runs in the request or, with its checkbox, on the queue; the checkbox defaults to the queue when there is one. *Signal* records and, with its checkbox, dispatches — recording is not delivery ([advanced-workflows.md](advanced-workflows.md)), and the page says which happened. *Start* refuses a name that is not a workflow and an input that is not an object before anything is written, and shows the form back filled in. *Cancel* is terminal, and the forms that would act on a cancelled execution are gone.

With `read_only` set, the action routes are not registered. A read-only dashboard has nothing to probe, and the forms are not rendered because the routes they would post to do not exist; the action paths answer `404`, and the start path — which shares its URL with the list — answers `405`, because the method is absent and the page is not.

## Refresh

Every page refreshes itself on an interval — `laraflow.dashboard.refresh` seconds, 10 by default, 0 for off — by asking for its own URL again, filters and cursor included, and swapping in the new content with the open disclosures kept open. It pauses while the tab is hidden, while a form control has focus, while an action form holds something not yet sent, and while a post is in flight, so an operator mid-thought is never interrupted; a response that is not a page (denied, gone, down) is ignored and the next tick tries again. A *Live* control in the header pauses and resumes it, and remembers the choice in the browser. The mechanism is one same-origin script the package serves beside its stylesheet, with nothing inline — an application's content-security policy need not loosen for it. Without script the page is static and the browser's reload does the same job.

## What is deliberately absent

- **A query the contract does not have.** No distinct-workflow list, no per-workflow `GROUP BY`, no full-text search over errors. Each would be a new public method with an index to justify, decided as such.
- **A separate "may act" permission.** One rule governs seeing and acting; `read_only` is the switch for an audience that may only see.
- **Editing anything.** No plan editor, no step editor, no configuration screen. The dashboard is a window.
- **Credentials and remembered values.** Not in a page, not in a response, not in a page's source.
- **Any JavaScript dependency, build tool, or CDN.** A single committed stylesheet and a single committed script for refresh and confirmation, both served by the package, nothing inline.

## Enforcement

- `tests/Feature/Dashboard/MountingTest.php` — unmounted means absent; mounted means present at the configured path; the configured middleware running first; the dependency guard naming `illuminate/view` when the view factory is not bound.
- `tests/Feature/Dashboard/AuthorizationTest.php` — the default rule in `local` and outside it; the callback; the gate; the rule belonging to one application instance.
- `tests/Feature/Dashboard/ExecutionsPageTest.php` — the list renders what the repository returns; the filters are the console's and mean the same; an unreadable filter is reported and the rest hold; pages follow the cursor forward and the trail back, carrying the filters.
- `tests/Feature/Dashboard/ExecutionPageTest.php` — the record, steps, attempts, wait, lineage; remembered keys and never their values; an unknown or malformed id is not found.
- `tests/Feature/Dashboard/OverviewPageTest.php` — counts by status, and what needs attention is what `laraflow:summary` says needs attention, under the same scope.
- `tests/Unit/Dashboard/CursorTrailTest.php` — *previous* replays a cursor the repository issued, never an offset, and a tampered trail is read as strings only.
- `tests/Feature/Dashboard/ActionsTest.php` — each action through its service, with its outcome said the console's way; the sync connection's run reported as a run; refusals printed as the runtime words them; the same authorization and the application's CSRF token on every post; the dashboard's own token check behind a session without `VerifyCsrfToken`; the separate action rule, by callback and by gate, and that it cannot widen the view rule; a step failure flashed redacted and truncated; a delivered signal's inline step failure reported as one; the forms offered matching the execution's state; an action mounted without a session refused rather than performed.
- `tests/Feature/Dashboard/ReadOnlyTest.php` — the action routes absent and the forms unrendered.
- `tests/Feature/Dashboard/AssetTest.php` — one stylesheet and one script, served by the package behind the check, with validators; the page links nothing else and carries nothing inline; the filter form is a working `GET` form without script and live with it; the refresh interval is the page's to declare and configuration turns it off.
- `tests/Feature/ProjectContractsTest.php` — the reach-past-the-contract test reads `src/Dashboard` and the views as it reads `src/Console`.
- `.github/actions/install-walk` — the two claims this document leads with, against a real application over real HTTP on every supported engine: nothing named `laraflow.*` is registered until `Dashboard::routes()` is called, and once mounted in production without a rule every page and both assets answer `403` until `Dashboard::auth()` says who may.
