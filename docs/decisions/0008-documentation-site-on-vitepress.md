# 0008 — The documentation site is built with VitePress from `docs/`

**Status:** Accepted (Milestone 11). Per-version publishing deferred when the milestone was narrowed ([0011](0011-one-optional-package.md)): the site is built from `main` alone until a second version is worth the machinery.

## Context

The roadmap calls for a versioned documentation site with the contracts as its spine, a guided tutorial, and an upgrade guide per minor. The README has grown to carry tutorial, guide, and reference at once.

## Decision

- **VitePress**, the tool the Laravel ecosystem's own package documentation most often uses, reading Markdown from `docs/` so that the contract documents stay where the code review and the contract tests already read them. The site's configuration lives in `docs/.vitepress/`; nothing in `src/` depends on it.
- **Versions** are published per minor: the site for `main` is the next release, and each release tag publishes its own copy under its version, with a version switcher. VitePress has no built-in versioning, so the release workflow builds each version into its own path; this is the cost of the choice.
- **Structure:** a short README (what Laraflow is, install, a five-minute start, links); a guided tutorial whose code is executed in CI, as the install walk is, so it cannot drift; guides built from today's README sections; the contract documents unchanged in substance; one upgrade guide per minor; and a reference page for every Public class and console command, generated from the code and cross-checked by the contract tests.
- **Hosting** is GitHub Pages, enabled by a maintainer.

## Rejected

- **MkDocs Material with `mike`.** Better built-in versioning, but the ecosystem's direction and the state of MkDocs' core development weighed against it.
- **Hand-written reference pages.** 130 Public classes would drift; generated pages checked by tests cannot.

## Consequences

The site build needs Node in CI, in its own job. Links stay relative Markdown links, which both GitHub and VitePress resolve, and the contract tests keep checking them.
