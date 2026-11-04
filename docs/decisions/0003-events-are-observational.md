# 0003 — Lifecycle events are observational and fire after the write

**Status:** Accepted (Milestone 4). Restated for the metrics bridges at Milestone 11.

## Context

Laravel's synchronous listeners can throw, and a throw inside the runner could abort a dispatch or mask a workflow's real failure. Listeners are also how applications build metrics, alerts, and audit trails, so they must see every transition.

## Decision

Every event fires strictly after the durable write it describes, and a listener that throws is logged and ignored. Events never alter execution state. Listeners whose failure matters must be queued listeners ([inspection-and-observability.md](../inspection-and-observability.md)).

## Rejected

- **Events before the write** ("about to complete"). A listener would observe a transition that may never persist.
- **Letting listener failures propagate.** Turns a metrics outage into a workflow outage.

## Consequences

Events can be lost if a process dies between the write and the dispatch, which is why the metrics contract takes gauges from a database snapshot rather than from event counts ([metrics.md](../metrics.md)).
