# Laraflow

**A framework for building integrations and durable workflows in Laravel.**

Applications often begin integrating external services with a few HTTP calls and queued jobs. As an integration grows, application code takes on more responsibility: coordinating multiple steps, carrying data between them, handling failures, keeping track of what ran, and figuring out where to resume when something goes wrong.

Laraflow gives that work a home. Workflows are ordered, named steps written as plain PHP classes. Connectors give external services a consistent place for authentication, requests, responses, and errors. A runner executes workflows and records every execution and step in your database so progress can be inspected and, when safe, continued.

> **Status: pre-release.** Laraflow is under active development and has not been tagged. The API is expected to change before the first release. See [Current limitations](#current-limitations) and [docs/public-api.md](docs/public-api.md) before depending on it.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Your first workflow](#your-first-workflow)
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

Laraflow is honest about what it does not yet do. These are the known gaps as of Milestone 1; they are the focus of the next milestones in [ROADMAP.md](ROADMAP.md).

- **Execution is synchronous only.** There is no queue integration yet. `run()` and `execute()` block until the workflow finishes (Milestone 2).
- **Recovery is manual.** A stale or failed execution is recovered by calling `execute()`; nothing scans for stale executions or re-dispatches them automatically (Milestone 2).
- **No automatic retries.** A failing step fails the workflow until something calls `execute()` again. `next_retry_at` is recorded but not acted on; backoff and retry policies are Milestone 3.
- **Attempt history is overwritten.** Each retry updates the step row in place; per-attempt history arrives with the retry work (Milestone 3).
- **Side effects are at-least-once.** If a worker dies between a step's external call and its checkpoint, recovery runs the step again. Use `$context->idempotencyKey()` to deduplicate; the package cannot make arbitrary side effects exactly-once.
- **No workflow versioning.** If a workflow's step list changes, in-flight executions of the old definition can only be finished by the old code; `execute()` rejects a mismatched definition rather than guessing.
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
