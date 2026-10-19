# Laraflow

**A framework for building integrations and durable workflows in Laravel.**

Applications often begin integrating external services with a few HTTP calls and queued jobs. As an integration grows, application code takes on more responsibility: coordinating multiple steps, carrying data between them, handling failures, keeping track of what ran, and figuring out where to resume when something goes wrong.

Laraflow is a project exploring a shared, Laravel-native way to build that kind of integration. Its aim is to make workflows understandable in code and make their execution visible and recoverable over time.

## The idea

A workflow describes a sequence of meaningful steps:

```text
Receive an event
      ↓
Load the relevant records
      ↓
Call an external service
      ↓
Save the result and notify the team
```

Each step should have a clear responsibility. The framework should coordinate the sequence, carry data between steps, and keep a record of progress. If a step fails or a worker stops, the application should have a useful account of what happened and a deliberate way to continue.

The larger ambition is for integrations to be composed from reusable connectors and workflows instead of being scattered across controllers, listeners, scheduled commands, and one-off jobs.

## What Laraflow is working toward

- **Readable workflows:** describe business processes as ordered, named steps that fit naturally into a Laravel application.
- **Reusable integrations:** give external-service connectors a consistent place for authentication, requests, responses, and errors.
- **Durable execution:** persist workflow progress so an execution can be inspected and, when safe, continued after interruption.
- **Useful failure handling:** make failures, attempts, and execution history understandable to application developers and operators.
- **Laravel-native extension:** build around familiar Laravel services such as the container, queues, configuration, events, and Eloquent.

## Design principles

### Recovery is part of the model

Persisting a status is only the beginning. A workflow runtime must define what happens after a process disappears, how completed work is recognized, and what data is needed to continue. Laraflow is being developed with those questions at the center.

### Be honest about side effects

A framework cannot generally guarantee that an external API call happened exactly once if a worker crashes before recording its result. Laraflow should make retry and recovery behavior explicit, and give developers tools to make repeatable operations safe.

### Keep workflows in application code

Workflows should be versionable, reviewable, and testable with the rest of a Laravel application. The goal is not to require a hosted service or a visual designer to understand what an integration does.

### Grow from a reliable core

Linear workflows and reliable recovery come before complex branching, large connector catalogs, or a dashboard. Each capability should build on clear execution and persistence contracts.

## A possible workflow

The intended developer experience is concise and recognizable:

```php
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

The workflow says what should happen and in what order. Individual steps contain the application-specific work. Laraflow's responsibility is to coordinate execution and provide the durable runtime around it.

## Where the project is

Laraflow is an early-stage project, not a released package. The repository is a place to develop and validate the core ideas: connector abstractions, workflow and step definitions, persisted execution records, and a runner.

The next major design challenge is making recovery genuinely reliable. That means defining execution state transitions, preserving the information later steps need, protecting executions from competing workers, and being clear about retries and side effects. Queue execution, operational tooling, broader compatibility, and a stable public API follow from that foundation.

See [ROADMAP.md](ROADMAP.md) for the planned development sequence.

## Contributing to the direction

The most useful questions for the project are practical ones:

- Which integration or automation problem is painful enough to deserve a shared framework?
- What should a workflow author be responsible for, and what should the runtime handle?
- What execution history would make a failure diagnosable?
- Which recovery guarantees are valuable and realistic for Laravel applications?

Laraflow is being shaped around those problems before committing to a stable API or release timeline.
