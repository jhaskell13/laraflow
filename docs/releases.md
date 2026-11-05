# Releases and support

## Versioning

Laraflow follows [semantic versioning](https://semver.org) as specified in [compatibility.md](compatibility.md). Before 1.0, a **minor** release may carry breaking changes; every one is marked **Breaking** in the [changelog](../CHANGELOG.md) and has an entry in [upgrading.md](upgrading.md). A **patch** release never does.

## Cadence

- **A minor release per milestone** of the [roadmap](../ROADMAP.md), when the milestone's audit is done — not on a calendar ([decision 0009](decisions/0009-one-release-per-milestone.md)). 0.7.0 is the last minor before 1.0.
- **Patch releases as needed**, for fixes and security fixes, from `main`.
- **Every release is drafted by CI and published by a person**: the `Release` workflow re-runs the whole matrix against the tag and drafts the notes from the changelog; a maintainer reads the draft and publishes it ([CONTRIBUTING.md](../CONTRIBUTING.md#cutting-a-release)).

## Support window

| Version | Bug fixes | Security fixes |
|---|---|---|
| The latest minor | Yes | Yes |
| The previous minor | No | For 90 days after the next minor is published |
| Anything older | No | No — upgrade |

The same window is in [SECURITY.md](../SECURITY.md), which is the authority for reporting a vulnerability. Upgrading is the remediation for an unsupported version, and [upgrading.md](upgrading.md) is the procedure: the schema is frozen, so `composer update` and `php artisan migrate` are always enough.

The post-1.0 policy — a long-term-support window and the rules for deprecations — is part of Milestone 12, and will be written here when it is decided.
