---
layout: home

hero:
  name: Laraflow
  text: Durable workflows for Laravel
  tagline: Multi-step automation that survives crashes, retries what failed, waits days for an approval, and tells you exactly what happened — on your own database and queue.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started
    - theme: alt
      text: Tutorial
      link: /playground
    - theme: alt
      text: Public API
      link: /public-api
    - theme: alt
      text: GitHub
      link: https://github.com/jhaskell13/laraflow

features:
  - title: Recoverable by contract
    details: Every step's result is checkpointed. A worker that dies mid-run is taken over; completed steps never run again, and the at-least-once boundary is stated, not hidden.
  - title: Retries, waits, and composition
    details: Per-step retry policies and timeouts, signals and timed waits, sub-workflows, fan-out with a join, and compensation that undoes completed steps.
  - title: Schedules and triggers
    details: Cron-declared workflows that start exactly once per tick across any number of schedulers, and signed HTTP endpoints for approvals and webhooks.
  - title: Observable without lock-in
    details: Lifecycle events with durations and identifiers, an exact metrics snapshot, a dashboard, JSON from every command, and optional OpenTelemetry traces.
---

## Install

```bash
composer require laraflow/laraflow
php artisan laraflow:install
php artisan laraflow:make-workflow FulfilOrder
```

[Getting started](getting-started.md) walks through a first workflow, its failure, and its recovery.
