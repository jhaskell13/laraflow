# Laraflow

**A framework for building integrations and durable workflows in Laravel.**

Applications often begin integrating external services with a few HTTP calls and queued jobs. As an integration grows, application code takes on more responsibility: coordinating multiple steps, carrying data between them, handling failures, keeping track of what ran, and figuring out where to resume when something goes wrong.

Laraflow gives that work a home. Workflows are ordered, named steps written as plain PHP classes. Connectors give external services a consistent place for authentication, requests, responses, and errors. A runner executes workflows and records every execution and step in your database so progress can be inspected and, when safe, continued.

> **Status: pre-release.** Laraflow is under active development and has not been tagged. The API is expected to change before the first release. See [Current limitations](#current-limitations) and [docs/public-api.md](docs/public-api.md) before depending on it.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Try it locally](#try-it-locally)
- [Configuration](#configuration)
- [Your first workflow](#your-first-workflow)
- [Running on a queue](#running-on-a-queue)
- [Inspecting executions](#inspecting-executions)
- [Your first connector](#your-first-connector)
- [Current limitations](#current-limitations)
- [Design principles](#design-principles)
- [Compatibility and support](#compatibility-and-support)
- [Contributing](#contributing)

## Requirements

| Dependency | Supported |
|------------|-----------|
| PHP        | 8.2, 8.3, 8.4 |
| Laravel    | 12.x |
| Database   | SQLite (tested), MySQL 8+, PostgreSQL 13+, MariaDB 10.6+ |

See [docs/compatibility.md](docs/compatibility.md) for the full policy, including what "supported" means at this stage.

## Installation

The package is not yet published on Packagist. Add the repository to your application's `composer.json` and require the development branch:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/jhaskell13/laraflow" }
    ]
}
```

```bash
composer require laraflow/laraflow:dev-main
```

Laravel's package auto-discovery registers `Laraflow\Core\LaraflowServiceProvider`. No manual provider registration is needed.

### Database tables

Laraflow stores executions in two tables, `laraflow_workflow_executions` and `laraflow_step_executions`. The package loads its own migrations, so you only need to run:

```bash
php artisan migrate
```

If you want to review or customise the migrations first, publish them and disable automatic loading:

```bash
php artisan vendor:publish --tag=laraflow-migrations
```

```dotenv
LARAFLOW_LOAD_MIGRATIONS=false
```

## Try it locally

The repository ships a playground (via Orchestra Testbench) and a set of experimental Artisan commands, so you can watch the durable runtime work without creating a Laravel application:

```bash
git clone https://github.com/jhaskell13/laraflow.git
cd laraflow
composer install
composer playground     # creates a SQLite playground database and runs the migrations
```

> Re-run `composer playground` after pulling changes. Until the schema is frozen (roadmap Milestone 6) Laraflow edits its migrations in place rather than adding new ones, so an existing database does **not** pick up schema changes — `migrate` considers those migrations already run. The playground database is disposable, so `composer playground` resets it.

Then, from the repository root:

```bash
vendor/bin/testbench laraflow:demo --fail   # the demo payment step fails on attempt 1
vendor/bin/testbench laraflow:list          # the failed execution, 1/3 steps completed
vendor/bin/testbench laraflow:execute 1     # recovery: step 1 skipped, step 2 succeeds on attempt 2
vendor/bin/testbench laraflow:show 1        # full record: steps, attempts, outputs, context
```

The queue path uses the same commands:

```bash
vendor/bin/testbench laraflow:demo --queue       # hands the execution to the queue
vendor/bin/testbench laraflow:recover --dry-run  # what recovery would do right now
```

The playground has no queue connection configured, so Laravel's default `sync` driver runs the dispatched job immediately in the same process — the execution is already complete when the command returns. Point `QUEUE_CONNECTION` at a real connection (and run `php artisan queue:work`) to see it execute out of process; see [docs/queue-and-recovery.md](docs/queue-and-recovery.md).

The demo workflow simulates an order: reserve inventory → charge payment → send receipt. With `--fail`, the payment step throws on its first attempt, leaving a persisted failed execution; `laraflow:execute` then recovers it from the checkpoint — the completed step is skipped, its context restored, and the retry reports the same idempotency key the first attempt used. Run `laraflow:demo` without the flag for the happy path.

The same five commands (`laraflow:demo`, `laraflow:list`, `laraflow:show`, `laraflow:execute`, `laraflow:recover`) are registered in any application that installs the package. They are experimental; see [docs/public-api.md](docs/public-api.md).

## Configuration

Publish the configuration file if you need to change defaults:

```bash
php artisan vendor:publish --tag=laraflow-config
```

This creates `config/laraflow.php`:

```php
return [
    'migrations' => [
        // Load migrations from the package (true) or manage published copies (false).
        'load' => env('LARAFLOW_LOAD_MIGRATIONS', true),
    ],

    'execution' => [
        // Seconds before a running execution's claim is considered abandoned
        // and may be reclaimed. Set it above your longest step.
        'stale_after' => env('LARAFLOW_STALE_AFTER', 300),
        'max_error_length' => env('LARAFLOW_MAX_ERROR_LENGTH', 65535),
    ],

    'queue' => [
        // null uses your application's default connection and queue.
        'connection' => env('LARAFLOW_QUEUE_CONNECTION'),
        'queue' => env('LARAFLOW_QUEUE'),
        // 1 = the queue does not retry; see docs/queue-and-recovery.md.
        'tries' => env('LARAFLOW_QUEUE_TRIES', 1),
        'backoff' => env('LARAFLOW_QUEUE_BACKOFF', 0),
        // Must stay below execution.stale_after.
        'timeout' => env('LARAFLOW_QUEUE_TIMEOUT', 60),
        'after_commit' => env('LARAFLOW_QUEUE_AFTER_COMMIT', true),
    ],

    'recovery' => [
        'limit' => env('LARAFLOW_RECOVERY_LIMIT', 100),
        // Executions whose steps reach this many attempts are abandoned
        // by recovery rather than retried forever.
        'max_attempts' => env('LARAFLOW_RECOVERY_MAX_ATTEMPTS', 3),
        // null disables recovery of never-dispatched pending executions.
        'pending_after' => env('LARAFLOW_RECOVERY_PENDING_AFTER'),
    ],

    'events' => [
        // Lifecycle events never carry input, output, or context. This
        // controls only the exception message on failure events.
        'include_error_messages' => env('LARAFLOW_EVENT_ERROR_MESSAGES', true),
    ],

    'integrations' => [
        'github' => [
            'token' => env('LARAFLOW_GITHUB_TOKEN'),
        ],
    ],
];
```

Keep credentials in your `.env` file, not in the published config.

## Your first workflow

A workflow is a class that lists its steps in order. Each step is a class implementing `Laraflow\Workflow\Step` and is resolved from the Laravel container, so you can type-hint dependencies in step constructors.

```php
<?php

namespace App\Workflows;

use Laraflow\Workflow\Workflow;

class SyncCustomerWorkflow extends Workflow
{
    public function steps(): array
    {
        return [
            LoadCustomer::class,
            SyncWithCrm::class,
            RecordSyncResult::class,
        ];
    }
}
```

Steps communicate through a `WorkflowContext`. A step reads what earlier steps stored, does its work, and stores results for later steps. Whatever a step returns from `handle()` is persisted as that step's output.

```php
<?php

namespace App\Workflows;

use App\Models\Customer;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

class LoadCustomer implements Step
{
    public function handle(WorkflowContext $context): mixed
    {
        $customer = Customer::findOrFail($context->get('customer_id'));

        $context->set('customer_email', $customer->email);
        $context->set('customer_name', $customer->name);

        // Returned values are persisted as the step output. Keep them JSON-friendly.
        return ['customer_id' => $customer->id];
    }
}
```

```php
class SyncWithCrm implements Step
{
    public function __construct(private CrmClient $crm) {}

    public function handle(WorkflowContext $context): mixed
    {
        $remoteId = $this->crm->upsertContact(
            email: $context->get('customer_email'),
            name: $context->get('customer_name'),
        );

        $context->set('crm_contact_id', $remoteId);

        return ['crm_contact_id' => $remoteId];
    }
}
```

### Running it

Resolve the `WorkflowRunner` from the container and call `run()` with optional input. Execution is synchronous: the call returns a `WorkflowExecution` handle once every step has completed, or throws the step's exception after persisting the failure. Input values seed the context, so steps read them with `$context->get('customer_id')`.

```php
use Laraflow\Workflow\WorkflowRunner;

$runner = app(WorkflowRunner::class);

$execution = $runner->run(new SyncCustomerWorkflow(), ['customer_id' => 42]);

$execution->id;            // store or display it
$execution->isCompleted(); // true
$execution->context;       // everything the steps stored
```

Creation and execution are separate when you need them to be:

```php
$execution = $runner->start(new SyncCustomerWorkflow(), ['customer_id' => 42]);
// ...persisted as pending with its full step plan; nothing has run...
$execution = $runner->execute($execution->id, new SyncCustomerWorkflow());
```

### Failure and recovery

If a step throws, the failure (message and exception class) is persisted on both the step and the execution, and the exception is rethrown. Calling `execute()` again on a failed execution **resumes it**: completed steps are skipped, their results are restored into the context from durable state, and the failed step runs again.

```php
try {
    $runner->run(new SyncCustomerWorkflow(), ['customer_id' => 42]);
} catch (\Throwable $e) {
    // Later — after fixing the cause — continue where it stopped:
    $runner->execute($executionId, new SyncCustomerWorkflow());
}
```

The same call recovers executions whose worker died mid-run: a `running` execution whose claim is older than `laraflow.execution.stale_after` (default 300 seconds) can be reclaimed, and the interrupted step runs again. Two workers can never both hold a live execution — claiming is an atomic compare-and-set, and a worker that loses its claim can no longer write durable state.

A step that repeats this way may re-perform external side effects — that is the **at-least-once contract**. Steps can deduplicate using `$context->idempotencyKey()`, which is stable across crashes and retries of the same step. The full contract, state machines, and crash-boundary behaviour are specified in [docs/execution-lifecycle.md](docs/execution-lifecycle.md).

### What gets persisted

`start()` (and therefore `run()`) creates one row in `laraflow_workflow_executions` and — up front, before anything runs — one row per step in `laraflow_step_executions`:

| Record | Fields |
|--------|--------|
| Workflow execution | workflow class, status (`pending`, `running`, `completed`, `failed`), input, context snapshot, error message and class, claim token/time, started/completed/failed timestamps |
| Step execution | step class, position, status, attempts, output, error message and class, started/completed timestamps, next retry time |

After every completed step, its output and the full context snapshot are checkpointed in one transaction. Input, outputs, and context values must be JSON-serializable (scalars and arrays); anything else is rejected with a clear exception naming the offending key.

## Running on a queue

`WorkflowDispatcher` is the asynchronous counterpart of `run()`. It persists the execution and its step plan, queues a job carrying only the execution id, and returns immediately:

```php
use Laraflow\Queue\WorkflowDispatcher;

$execution = app(WorkflowDispatcher::class)
    ->dispatch(new SyncCustomerWorkflow(), ['customer_id' => 42]);

$execution->id;          // store it, show it, poll it
$execution->isPending(); // true — a worker will run it
```

A worker is an ordinary Laravel worker; there is no Laraflow-specific worker command:

```bash
php artisan queue:work --queue=workflows
```

Note that Laravel's default `sync` connection (when `QUEUE_CONNECTION` is unset) runs the job immediately in the dispatching process, so `dispatch()` returns an already-finished execution. Configure a real queue connection to get asynchronous execution.

The worker reads the workflow class from the execution record, so the queue payload never contains your input, your step data, or a serialized workflow object. Connection and queue come from `laraflow.queue.*` or per-dispatch arguments.

**The queue does not retry by default** (`laraflow.queue.tries` is `1`). One delivery means one execution attempt, so Laravel's retries and Laraflow's step attempts cannot multiply on two unrelated schedules. A failing step is persisted as `failed` and recorded in `failed_jobs`; re-running it is an explicit act. Duplicate deliveries are safe: claiming is an atomic compare-and-set, so a second delivery simply no-ops instead of running the workflow twice or failing the job.

### Recovering interrupted work

A worker that is killed mid-step leaves its execution `running` with an aging claim. Schedule the recovery command to find and retry such work:

```php
// routes/console.php
Schedule::command('laraflow:recover')->everyFiveMinutes()->withoutOverlapping();
```

```bash
php artisan laraflow:recover --dry-run   # report only; changes nothing
php artisan laraflow:recover             # re-dispatch interrupted executions
php artisan laraflow:recover --sync      # run them here instead of queueing
```

Recovery is bounded: at most `--limit` executions per run; an execution whose steps have reached `--max-attempts` (default 3) is reported as **abandoned** rather than retried, so a crash-looping workflow can never be re-dispatched forever; and an execution whose workflow class no longer exists is reported as **unrecoverable** rather than queued into guaranteed failure. The command exits non-zero when anything was abandoned, unrecoverable, or failed, so a scheduled run surfaces stuck work through your existing alerts. Overriding the attempt bound deliberately is `php artisan laraflow:execute <id>`.

### Lifecycle events

Laraflow fires events for dispatch, execution start, each step's start/completion/failure, execution completion/failure, and scheduled retries. Each fires **after** the durable write it describes, and none of them carry workflow input, step output, or context — only identifiers and metadata.

```php
use Laraflow\Events\WorkflowExecutionFailed;

Event::listen(function (WorkflowExecutionFailed $event) {
    Log::error('Workflow failed', [
        'execution' => $event->executionId,
        'workflow' => $event->workflow,
        'error' => $event->errorClass,
    ]);
});
```

Queueing, retry interaction, recovery policy, and event payload safety are specified in full in [docs/queue-and-recovery.md](docs/queue-and-recovery.md).

## Inspecting executions

The stable way to read executions is through the `Laraflow\Workflow\ExecutionRepository` contract, which returns plain `WorkflowExecution` and `StepExecution` value objects:

```php
use Laraflow\Workflow\ExecutionRepository;

$repository = app(ExecutionRepository::class);

$execution = $repository->find($executionId);

$execution->status;      // ExecutionStatus enum
$execution->isFailed();  // bool
$execution->error;       // message of the failing step, if any
$execution->context;     // persisted context snapshot
$execution->startedAt;   // ?DateTimeImmutable

foreach ($repository->steps($execution) as $step) {
    echo "{$step->position}: {$step->step} [{$step->status->value}]";

    if ($step->isFailed()) {
        echo " — {$step->error}";
    }
}
```

The Eloquent models `WorkflowExecutionModel` and `StepExecutionModel` exist but are internal. Their table layout will change; query them only for debugging.

## Your first connector

Connectors wrap an external service behind a small, testable contract. Laraflow ships with a GitHub connector as a working example.

### Using the bundled GitHub connector

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

`ConnectorResponse` exposes `status()`, `headers()`, `body()`, `successful()`, and `failed()`. Connection failures throw `Laraflow\Exceptions\TransportException`.

### Writing your own

Implement `Laraflow\Integration\Connector`, and optionally an `Authenticator` to inject credentials into request options:

```php
<?php

namespace App\Integrations;

use Illuminate\Support\Facades\Http;
use Laraflow\Auth\Authenticator;
use Laraflow\Integration\Connector;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;

class CrmConnector implements Connector
{
    public function __construct(private Authenticator $authenticator) {}

    public function name(): string
    {
        return 'crm';
    }

    public function request(ConnectorRequest $request): ConnectorResponse
    {
        $options = $this->authenticator->authenticate($request->options);

        $response = Http::baseUrl(config('services.crm.url'))
            ->acceptJson()
            ->send($request->method, $request->uri, $options);

        return new ConnectorResponse(
            status: $response->status(),
            headers: $response->headers(),
            body: $response->json(),
        );
    }
}
```

Register it in one of your application's service providers. Connectors are built lazily and cached for the lifetime of the application:

```php
use Laraflow\Core\LaraflowManager;

public function boot(): void
{
    app(LaraflowManager::class)->register(
        'crm',
        fn () => new CrmConnector(new BearerTokenAuthenticator(config('services.crm.token'))),
    );
}
```

Steps can then type-hint `LaraflowManager` and call `->connector('crm')`.

## Current limitations

Laraflow is honest about what it does not yet do. These are the known gaps as of Milestone 2; they are the focus of the next milestones in [ROADMAP.md](ROADMAP.md).

- **No automatic retries.** A *failed* execution is never retried on its own: the queue does not retry it (by design) and `laraflow:recover` only picks up *interrupted* work, not failures. Retrying a failure is an explicit act until per-step retry policy, backoff, and `next_retry_at` land in Milestone 3.
- **No timeouts or cancellation.** A step that hangs is bounded only by the queue worker's timeout, and there is no way to cancel a running execution (Milestone 3).
- **Attempt history is overwritten.** Each retry updates the step row in place; per-attempt history arrives with the retry work (Milestone 3).
- **Side effects are at-least-once.** If a worker dies between a step's external call and its checkpoint, recovery runs the step again. Use `$context->idempotencyKey()` to deduplicate; the package cannot make arbitrary side effects exactly-once.
- **No workflow versioning.** If a workflow's step list changes, in-flight executions of the old definition can only be finished by the old code; `execute()` rejects a mismatched definition rather than guessing.
- **No stable querying or pruning API.** Listing and filtering executions means the internal Eloquent models or `laraflow:list`; there is no retention or pruning command yet (Milestone 4).
- **One bundled connector.** The GitHub connector is a prototype. Timeouts, retries, rate limiting, and pagination are not configurable yet (Milestone 5).
- **Schema is not frozen.** Migrations may change in place until the first tagged release; there is no upgrade path between development snapshots.

## Design principles

**Recovery is part of the model.** Persisting a status is only the beginning. A workflow runtime must define what happens after a process disappears, how completed work is recognised, and what data is needed to continue.

**Be honest about side effects.** A framework cannot generally guarantee that an external API call happened exactly once if a worker crashes before recording its result. Laraflow makes retry and recovery behaviour explicit and gives developers tools to make repeatable operations safe.

**Keep workflows in application code.** Workflows should be versionable, reviewable, and testable with the rest of a Laravel application. No hosted service or visual designer is required to understand what an integration does.

**Grow from a reliable core.** Linear workflows and reliable recovery come before complex branching, large connector catalogs, or a dashboard.

## Compatibility and support

Laraflow follows [semantic versioning](https://semver.org) once tagged. Until `1.0`, minor versions may contain breaking changes and will say so in [CHANGELOG.md](CHANGELOG.md). The supported PHP, Laravel, and database versions, and the policy for dropping them, are in [docs/compatibility.md](docs/compatibility.md). Which classes are public, experimental, or internal is in [docs/public-api.md](docs/public-api.md).

Security issues should be reported privately as described in [SECURITY.md](SECURITY.md).

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, testing, and the kind of changes most likely to be accepted at this stage. The development sequence is in [ROADMAP.md](ROADMAP.md).

## License

Laraflow is open-sourced software licensed under the [MIT license](LICENSE).
