# 0006 — Public classes are final; the repository is a public reader and an experimental store

**Status:** Accepted (0.6.0).

## Context

After 1.0, anything a user can subclass or implement is part of the compatibility promise. `ExecutionRepository` had grown to roughly thirty methods mixing reads every application uses with storage operations only a persistence driver implements, and most concrete classes were open for extension without being designed for it.

## Decision

Concrete Public classes are `final` unless designed for extension; the exceptions are listed and enforced by a test. `ExecutionRepository` extends a small Public `ExecutionReader` (queries) and an Experimental `ExecutionStore` (claims, scans, retention); read-only code type-hints the reader ([public-api.md](../public-api.md)).

## Rejected

- **Freezing the whole repository at 1.0.** Every later storage change would be a major release.
- **Leaving classes open and documenting "do not extend".** Unenforceable, and users extend anyway.

## Consequences

Extension happens through interfaces bound in the container. The store contract can still change until a second persistence driver proves it.
