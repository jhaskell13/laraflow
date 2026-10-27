<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Throwable;

/**
 * Rewrites exception messages before Laraflow retains them anywhere:
 * execution and step rows, attempt history, log records, and failure
 * events all receive the redacted form. Configure an implementation via
 * laraflow.errors.redactor when step exceptions in your application may
 * carry secrets or personal data.
 *
 * The exception the application catches is untouched — redaction
 * governs what Laraflow keeps, not what your code sees.
 *
 * A redactor must never throw: it runs on the failure path, where an
 * exception from it would bury the diagnosis it exists to clean up.
 * Return a constant placeholder if in doubt.
 */
interface ErrorRedactor
{
    public function redact(Throwable $exception, string $message): string;
}
