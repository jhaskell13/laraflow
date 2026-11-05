# Community connectors

Laraflow ships two connectors, GitHub and Slack, as examples of the extension points — not as the start of a catalog. Connectors for every other service live in their own packages, written by the people who use those services, and are held to the same standard as the bundled two by the published contract test, `Laraflow\Testing\ConnectorContract`.

How to write a connector is in [connectors.md](connectors.md); the step-by-step recipe is in [CONTRIBUTING.md](../CONTRIBUTING.md#contributing-a-connector).

## Publishing one

A connector package needs nothing from this repository beyond `laraflow/laraflow` itself:

1. **Scaffold it** with `php artisan laraflow:make-connector`, in a package of your own named `vendor/laraflow-{service}`, and register it from your package's service provider with `LaraflowManager::register()`.
2. **Test it without credentials or network.** Run `ConnectorContract::assertHeldBy()` against it with HTTP faked, alongside your own tests for whatever is specific to the provider ([connectors.md](connectors.md#testing-a-connector)).
3. **Run those tests in your own CI** on the PHP and Laravel versions Laraflow supports ([compatibility.md](compatibility.md)).

## What a listed connector must do

A connector is listed below when its repository shows, in public CI:

1. **The contract test passes** against the current Laraflow minor.
2. **Tests run without credentials or network.** HTTP is faked; nothing in CI holds a real token.
3. **No credential is held by the connector**, and none is configured at the connector level — what the contract test checks, restated because it is the rule that matters most.
4. **It reaches no Laraflow internals.** Only classes [public-api.md](public-api.md) marks Public or Experimental.
5. **It is licensed** under a license compatible with Laraflow's MIT license, and says so in `composer.json`.
6. **It is maintained**: it supports the current Laraflow minor within 90 days of that minor's release.

The listing says "this connector meets the contract", which the contract test makes checkable; it does not say Laraflow's maintainers have reviewed or will support it.

## Adding or removing a listing

**To add one,** open a pull request that adds a row to the table below — package, service, and a link to the repository — and link the CI run that shows the contract test passing. A maintainer checks that run and merges: the contract test is the reviewer, by design.

**A listing is removed** when its CI stops showing a passing contract run against the current minor for 90 days after that minor's release, or when the repository is archived or deleted. Removal is a pull request like any other, linking the evidence; the author is mentioned on it and can restore the listing by fixing the cause.

## Listed connectors

| Package | Service | Repository |
|---|---|---|

None are listed yet.
