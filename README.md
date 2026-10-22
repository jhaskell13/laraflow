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

Resolve the `WorkflowRunner` from the container and call `run()`. Execution is synchronous: the call returns once every step has completed, or throws the step's exception after persisting the failure.

```php
use Laraflow\Workflow\WorkflowRunner;

$runner = app(WorkflowRunner::class);

$context = $runner->run(new SyncCustomerWorkflow());
```

> **API change ahead.** `run()` currently returns the `WorkflowContext`. The next milestone changes it to return a `WorkflowExecution` handle, adds `start()` and `execute()`, and lets you pass workflow input. The reasoning is recorded in [docs/public-api.md](docs/public-api.md#runner-method-naming).

Right now there is no way to pass input into `run()`, so steps must obtain their starting data themselves (for example from the container or configuration). Input handling arrives with the lifecycle work in Milestone 1.

### What gets persisted

Every call to `run()` creates one row in `laraflow_workflow_executions` and one row per step in `laraflow_step_executions`:

| Record | Fields |
|--------|--------|
| Workflow execution | workflow class, status (`pending`, `running`, `completed`, `failed`), input, started/completed/failed timestamps |
| Step execution | step class, position, status, attempts, input, output, error message, started/completed timestamps, next retry time |

Step status transitions are written before and after each step runs, so a failure records which step failed and why.

## Inspecting executions

The stable way to read executions is through the `Laraflow\Workflow\ExecutionRepository` contract, which returns plain `WorkflowExecution` and `StepExecution` value objects:

```php
use Laraflow\Workflow\ExecutionRepository;

$repository = app(ExecutionRepository::class);

$execution = $repository->find($executionId);

$execution->status;      // ExecutionStatus enum
$execution->isFailed();  // bool
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

Laraflow is honest about what it does not yet do. These are the known gaps as of Milestone 0; they are the focus of the next milestones in [ROADMAP.md](ROADMAP.md).

- **Execution is synchronous only.** There is no queue integration. `run()` blocks until the workflow finishes.
- **Recovery is not yet safe.** A `resume()` method exists but is experimental and incomplete: it does not restore earlier step results into the context, does not create records for steps that never started, does not validate the workflow definition against the persisted plan, and does not protect against two workers resuming the same execution. Do not rely on it.
- **A crashed worker leaves records in `running`.** Nothing detects or recovers stale executions yet.
- **No retries.** A failing step fails the workflow. `attempts` and `next_retry_at` are recorded but not acted on.
- **No workflow input.** `run()` does not accept input; context starts empty.
- **Outputs must be JSON-friendly.** Step outputs are stored in a JSON column cast to an array. Returning objects or non-array scalars is unsupported and may be mangled or rejected by your database.
- **Context is not persisted.** Only step outputs are stored; the in-memory context is lost when the process ends.
- **Side effects are at-least-once.** If a step performs an external call and the process dies before the result is recorded, a future resume may run that step again. The package will provide idempotency tools; it cannot make arbitrary side effects exactly-once.
- **One bundled connector.** The GitHub connector is a prototype. Timeouts, retries, rate limiting, and pagination are not configurable yet.
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
