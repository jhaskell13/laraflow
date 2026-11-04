# Community connectors

Laraflow ships two connectors, GitHub and Slack, as examples of the extension points — not as the start of a catalog. Connectors for every other service are meant to live in their own packages, written by the people who use those services, and held to the same standard as the bundled two by the published contract test. This document is the contract for that ecosystem: what the connector template gives an author, what a connector must do to be listed here, and how a listing is added or removed.

How to write a connector is in [connectors.md](connectors.md); the step-by-step recipe is in [CONTRIBUTING.md](../CONTRIBUTING.md#contributing-a-connector).

## The template

`laraflow/connector-skeleton` (Milestone 11) is a template repository for a connector package. Starting from it gives an author, with no Laraflow file edited:

- A package named `vendor/laraflow-{service}`, auto-discovered, whose service provider registers the connector with `LaraflowManager::register()`.
- A connector class extending `HttpConnector`, an error mapper extending `StatusErrorMapper`, and configuration that reads credentials from the connection, never from the connector.
- A test suite that runs `Laraflow\Testing\ConnectorContract` against the connector with HTTP faked and stray requests prevented, plus a place for the provider-specific tests the contract cannot know about.
- A CI workflow that runs the suite on every supported PHP and Laravel pairing and calls Laraflow's **reusable contract workflow**, so the contract check a reviewer relies on is the same one this repository runs, not a copy that can drift.

The template is tested in this repository's CI: a connector is generated from it and its suite, contract test included, must pass. A template that cannot produce a passing connector is a defect here, not in the next author's package.

## What a listed connector must do

A connector is listed below when its repository shows, in public CI:

1. **The contract test passes** through the reusable contract workflow, against the current Laraflow minor.
2. **Tests run without credentials or network.** HTTP is faked; nothing in CI holds a real token.
3. **No credential is held by the connector**, and none is configured at the connector level — exactly what the contract test checks, restated because it is the rule that matters most.
4. **It reaches no Laraflow internals.** Only classes [public-api.md](public-api.md) marks Public or Experimental.
5. **It is licensed** under a license compatible with Laraflow's MIT license, and says so in `composer.json`.
6. **It is maintained**: it supports the current Laraflow minor within 90 days of that minor's release.

Nothing here is a judgement of the code's quality beyond these points. The listing says "this connector meets the contract", which the contract test makes checkable; it does not say Laraflow's maintainers have reviewed or will support it.

## Adding or removing a listing

**To add one,** open a pull request that adds a row to the table below — package name, service, and a link to the repository. The pull request template asks for the CI run that shows the contract workflow passing; a maintainer checks that run and merges. That is the whole review: the contract test is the reviewer, by design.

**A listing is removed** when its CI stops showing a passing contract run against the current minor for 90 days after that minor's release, or when the repository is archived or deleted. Removal is a pull request like any other, linking the evidence; the author is mentioned on it and can restore the listing by fixing the cause.

## Listed connectors

| Package | Service | Repository |
|---|---|---|

None are listed yet. The template and the reusable contract workflow arrive in Milestone 11; the first listing is that milestone's exit criterion.
