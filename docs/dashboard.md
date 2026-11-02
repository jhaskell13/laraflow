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

    // How many executions a list page shows, up to the repository's
    // own ceiling.
    'per_page' => env('LARAFLOW_DASHBOARD_PER_PAGE', 25),
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

Anything else is a `403`. The default is deliberately narrow: a dashboard mounted in production without an explicit rule denies everyone, which is the failure mode that gets noticed and fixed. The same callback governs the actions; there is no separate "may act" rule in this milestone, because an operator who may see a failed execution is the operator who recovers it. An application that wants to separate the two mounts a read-only dashboard for one audience and a full one for the other.

## Pages

| Page | Reads | Shows |
|---|---|---|
| **Overview** | `count()` per status; the attention scan `laraflow:summary` runs | Counts by status; retries and waits due; running steps past their deadline; the oldest unfinished execution |
| **Executions** | `list()`, `count()`, `stepProgress()` | A filterable page of executions: id, workflow, status, step progress, age, correlation, tenant, duration |
| **Execution** | `find()`, `steps()`, `attemptHistory()`, `IdempotencyStore::records()` | Everything `laraflow:show --attempts` prints: the record, its steps, each step's attempts, what it is waiting on, its plan version, its parent or children, and the keys it has remembered — never their values |

The overview's "what needs attention" is the same computation `laraflow:summary` performs. It is extracted from the command into a service both call, so the console and the browser cannot disagree about what is wrong.

### Filtering

The executions page filters by exactly what `ExecutionFilter` offers — status, workflow, correlation id, tenant id, and a creation window — because those are the criteria the schema serves from an index, and an unindexed filter on a high-volume table is how observability tooling takes an application down ([inspection-and-observability.md](inspection-and-observability.md), *Filtering*). The filter form's workflow field is free text, as `laraflow:list --workflow=` is: the contract has no method that lists distinct workflow classes, and one would be a scan over the table. The field suggests the workflows visible on the current page. If that proves insufficient, the method is added to `ExecutionRepository` as a public change with its own index justification, not slipped in for the dashboard.

### Pagination

`ExecutionRepository::list()` paginates by keyset: a cursor names the last row of a page and the next page starts strictly after it. That is stable under concurrent inserts and costs the same on page 1000 as on page 1, and it is one-directional on purpose. The dashboard offers *next* and *first*; *previous* works by carrying the cursors already visited in the query string, so stepping back replays a cursor the repository issued rather than inventing an offset. There is no "page 7 of 40" — a count of pages over a table being written to would be wrong before it was rendered.

## Actions

Four, each the browser form of a console command, each through the same service:

| Action | Console equivalent | Service call |
|---|---|---|
| Execute / recover | `laraflow:execute {id}` and `--now` | `WorkflowRunner::execute()`, or `WorkflowDispatcher::dispatchExecution()` when queued |
| Cancel | `laraflow:cancel {id} --reason=` | `WorkflowRunner::cancel()` |
| Signal | — (the application calls `signal()`) | `WorkflowRunner::signal()` |
| Start | `laraflow:start {workflow} --input=` | the same resolution and validation, then `WorkflowRunner` or `WorkflowDispatcher` |

Every action is a `POST` with CSRF protection from the application's middleware and passes the same authorization check as every page. Confirmation is two-layered: with script, a dialog before the post; without, each action sits behind a disclosure and its own labelled button, so nothing happens on a stray click either way. The page after an action is the execution's detail page, showing the record as it now stands; the outcome is reported the way the console reports it, from the same `Outcome` definition — a wait as a wait, a retry as a retry, never a parked execution as "done". Whether a dispatch ran the job in the request (a `sync` connection does) is judged from the record, not from configuration, for the reason the Phase C audit recorded.

*Execute* runs in the request or, with its checkbox, on the queue; the checkbox defaults to the queue when there is one. *Signal* records and, with its checkbox, dispatches — recording is not delivery ([advanced-workflows.md](advanced-workflows.md)), and the page says which happened. *Start* refuses a name that is not a workflow and an input that is not an object before anything is written, and shows the form back filled in. *Cancel* is terminal, and the forms that would act on a cancelled execution are gone.

With `read_only` set, the action routes are not registered. A read-only dashboard has nothing to probe, and the forms are not rendered because the routes they would post to do not exist; the action paths answer `404`, and the start path — which shares its URL with the list — answers `405`, because the method is absent and the page is not.

## Refresh

The executions and execution pages refresh themselves on an interval, preserving the filters and cursor in force, and pause when the tab is not visible. The interval is configurable and the mechanism is a plain request for the same page; without script the page is static and the browser's reload does the same job.

## What is deliberately absent

- **A query the contract does not have.** No distinct-workflow list, no per-workflow `GROUP BY`, no full-text search over errors. Each would be a new public method with an index to justify, decided as such.
- **A separate "may act" permission.** One rule governs seeing and acting; `read_only` is the switch for an audience that may only see.
- **Editing anything.** No plan editor, no step editor, no configuration screen. The dashboard is a window.
- **Credentials and remembered values.** Not in a page, not in a response, not in a page's source.
- **Any JavaScript dependency, build tool, or CDN.** A single committed stylesheet and a few lines of inline script for refresh and confirmation.

## Enforcement

- `tests/Feature/Dashboard/MountingTest.php` — unmounted means absent; mounted means present at the configured path; the dependency guard names what is missing.
- `tests/Feature/Dashboard/AuthorizationTest.php` — the default rule in `local` and outside it; the callback; the gate; the stylesheet behind the same check.
- `tests/Feature/Dashboard/ExecutionsPageTest.php` — the list renders what the repository returns; the filters are the console's and mean the same; an unreadable filter is reported and the rest hold; pages follow the cursor forward and the trail back, carrying the filters.
- `tests/Feature/Dashboard/ExecutionPageTest.php` — the record, steps, attempts, wait, lineage; remembered keys and never their values; an unknown or malformed id is not found.
- `tests/Feature/Dashboard/OverviewPageTest.php` — counts by status, and what needs attention is what `laraflow:summary` says needs attention, under the same scope.
- `tests/Feature/Dashboard/ActionsTest.php` — each action through its service, with its outcome said the console's way; the sync connection's run reported as a run; refusals printed as the runtime words them; the same authorization and the application's CSRF token on every post; the forms offered matching the execution's state.
- `tests/Feature/Dashboard/ReadOnlyTest.php` — the action routes absent and the forms unrendered.
- `tests/Feature/ProjectContractsTest.php` — the reach-past-the-contract test reads `src/Dashboard` and the views as it reads `src/Console`.
