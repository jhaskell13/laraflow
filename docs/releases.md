# Releases and support

## Versioning

Laraflow follows [semantic versioning](https://semver.org) as specified in [compatibility.md](compatibility.md).

- **Before 1.0**, a **minor** release may carry breaking changes; every one is marked **Breaking** in the [changelog](../CHANGELOG.md) and has an entry in [upgrading.md](upgrading.md). A **patch** release never does.
- **From 1.0 onward**, a breaking change to anything [public-api.md](public-api.md) marks **Public** requires a **major** release. Minors add; patches fix. What "Public" covers, and the one surface deliberately left outside it, are in [decision 0012](decisions/0012-what-1.0-freezes.md).

## Cadence

- **Before 1.0:** a minor release per milestone of the [roadmap](../ROADMAP.md), when the milestone's audit is done — not on a calendar ([decision 0009](decisions/0009-one-release-per-milestone.md)). 0.7.0 was the last minor before 1.0.
- **From 1.0:** minor releases when work is ready rather than on a schedule. The roadmap's milestone sequence ends at 1.0; what follows is driven by issues, and there is no release calendar to miss.
- **Patch releases as needed**, for fixes and security fixes, from `main`.
- **Every release is drafted by CI and published by a person**: the `Release` workflow re-runs the whole matrix against the tag and drafts the notes from the changelog; a maintainer reads the draft and publishes it ([CONTRIBUTING.md](../CONTRIBUTING.md#cutting-a-release)).

## Support window

| Version | Bug fixes | Security fixes |
|---|---|---|
| The latest minor of the current major | Yes | Yes |
| The previous minor of the current major | No | For 90 days after the next minor is published |
| The last minor of the previous major | No | For **12 months** after the next major is published |
| Anything older | No | No — upgrade |

The third row is the long-term-support commitment, and it is the only one that outlives a major. It exists because a major release is the one upgrade that can require application changes, and an application should get a year to make them without running unpatched. It applies to the **last** minor of the outgoing major — 1.9 if that is where 1.x ends — not to every 1.x release, because backporting to a line nobody is being asked to stay on helps no one.

The same window is in [SECURITY.md](../SECURITY.md), which is the authority for reporting a vulnerability. Upgrading is the remediation for an unsupported version, and [upgrading.md](upgrading.md) is the procedure: the schema is frozen, so `composer update` and `php artisan migrate` are always enough.

There is no commercial support and no response-time guarantee. This is a window during which a security fix will be *made*, not a service level.

## How something is removed after 1.0

The deprecation path is in [compatibility.md](compatibility.md#how-a-deprecation-works) and does not change at 1.0; what changes is when removal is allowed.

1. The docblock gains `@deprecated` naming the replacement — what an IDE and static analysis surface at the call site.
2. The changelog lists it under *Deprecated* in the release that introduces it, with the replacement and the planned removal.
3. Removal happens in the **next major**, and no sooner — with at least one minor release carrying the deprecation first, so an application that upgrades minors promptly is never surprised by a major.

Deprecated code keeps working and keeps its tests until it is removed. Laraflow does not emit runtime deprecation notices for its own API: the suite runs with `failOnDeprecation`, and a library that warns on every call punishes the applications that upgrade most carefully.

The upgrade checker shipped with 1.0 inspects an installed application and names what must change before the upgrade; [upgrading.md](upgrading.md) is where it is documented.
