# Getting started

Install Laraflow into a Laravel application, write a first workflow, watch it fail, and recover it from its checkpoint. For the package's requirements and the one-line install, see the [README](../README.md#installation).

## Database tables

Laraflow stores executions in four tables: `laraflow_workflow_executions`, `laraflow_step_executions`, `laraflow_step_attempts` (the append-only per-attempt history), and `laraflow_idempotency_keys` (what steps have remembered). The package loads its own migrations, so you only need to run:

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

## Your first workflow

Scaffold both halves — `php artisan laraflow:make-workflow SyncCustomerWorkflow` and `php artisan laraflow:make-step LoadCustomer` (`--conditional` and `--sub-workflow` for the other two kinds of step) — or write them by hand. A workflow is a class that lists its steps in order. Each step is a class implementing `Laraflow\Workflow\Step` and is resolved from the Laravel container, so you can type-hint dependencies in step constructors.

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

> Once you enable [retries](guide.md#retries-timeouts-and-cancellation), a retryable failure returns a non-terminal `retrying` handle instead of throwing. Check `$execution->isRetrying()`, or leave retries off for workflows you run in-process.

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

The same from the console, without writing a caller — the class fully qualified or by its bare name under `App\Workflows`, the input as a JSON object, `--queue` to dispatch instead:

```bash
php artisan laraflow:start SyncCustomerWorkflow --input='{"customer_id": 42}'
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

A step that repeats this way may re-perform external side effects — that is the **at-least-once contract**. Wrap a side effect in `$context->remember('charge', fn () => $payments->charge(...))` and it runs at most once per execution across every retry, recovery, and worker; a worker that dies *after* the charge and *before* its result was recorded leaves evidence, and the next attempt is told the outcome is uncertain rather than charging again ([docs/idempotency.md](idempotency.md)). The full execution contract, state machines, and crash-boundary behaviour are specified in [docs/execution-lifecycle.md](execution-lifecycle.md).

### What gets persisted

`start()` (and therefore `run()`) creates one row in `laraflow_workflow_executions` and — up front, before anything runs — one row per step in `laraflow_step_executions`. Every finished attempt of a step then appends one row to `laraflow_step_attempts`:

| Record | Fields |
|--------|--------|
| Workflow execution | workflow class, status (`pending`, `running`, `completed`, `failed`, `retrying`, `waiting`, `compensating`, `cancelled`), plan version, input, context snapshot, error message and class, claim token/time, started/completed/failed timestamps, next retry/resume time, cancellation request/time/reason, parent linkage for sub-workflows and fan-out members |
| Step execution | step class, position, status (`pending`, `running`, `completed`, `failed`, `retrying`, `skipped`, `waiting`), attempts, output, error message and class, started/completed/failed timestamps, current attempt's (or signal wait's) deadline, next retry/resume time, awaited signal name, and — on a compensation row — the step it undoes |
| Step attempt | attempt number, outcome (`completed`, `failed`, `timed_out`, `interrupted`), error message and class, started/ended timestamps, duration, scheduled retry time |

After every completed step, its output and the full context snapshot are checkpointed in one transaction. Input, outputs, and context values must be JSON-serializable (scalars and arrays); anything else is rejected with a clear exception naming the offending key.
