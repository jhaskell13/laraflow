# 0009 — Milestone 11 is 0.7.0 and Milestone 12 is 1.0.0

**Status:** Accepted (Milestone 11).

## Context

Milestone 11 (ecosystem, observability, documentation) is the largest milestone so far, and splitting it across two minors was considered.

## Decision

One release per milestone, as every milestone since the MVP has been: Milestone 11 ships as **0.7.0** and Milestone 12 as **1.0.0**. Work inside a milestone lands on `main` step by step behind green CI, but is released together, after the milestone's hostile audit.

## Rejected

- **Splitting Milestone 11 into 0.7.0 and 0.8.0.** Earlier delivery of part of the milestone, at the price of a release whose documentation does not yet describe it.

## Consequences

The changelog's *Unreleased* section accumulates the whole milestone. 0.7.0 is the last minor before 1.0, so it carries the last pre-1.0 breaking changes the compatibility policy allows; anything that must break after it waits for 2.0.
