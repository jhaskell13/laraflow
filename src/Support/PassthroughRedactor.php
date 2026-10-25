<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Throwable;

/**
 * @internal Default binding for ErrorRedactor, which is the public
 * contract; configure laraflow.errors.redactor to replace it.
 *
 * The default: messages pass through unchanged.
 */
class PassthroughRedactor implements ErrorRedactor
{
    public function redact(Throwable $exception, string $message): string
    {
        return $message;
    }
}
