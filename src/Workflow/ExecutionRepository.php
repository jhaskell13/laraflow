<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * Durable storage for workflow executions: the read contract and the
 * storage-driver contract together.
 *
 * Until 0.6.0 this was one thirty-method interface, all of it Public,
 * which would have frozen every engine-facing write at 1.0. It is now
 * the union of ExecutionReader (Public — the queries applications, the
 * console, and the dashboard use) and ExecutionStore (@experimental —
 * claim, checkpoint, park, scans, retention). Every method kept its
 * signature and its documentation moved with it, so a binding, a
 * type-hint, or a custom implementation written against this interface
 * keeps working unchanged; the container resolves all three names to
 * the same singleton. New code that only reads should ask for
 * ExecutionReader.
 *
 * Implementations must satisfy the guarantees documented in
 * docs/execution-lifecycle.md: atomic plan creation, compare-and-set
 * claiming, and transactional, claim-verified checkpoints.
 */
interface ExecutionRepository extends ExecutionReader, ExecutionStore {}
