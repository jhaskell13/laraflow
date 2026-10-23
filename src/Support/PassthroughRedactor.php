<?php

declare(strict_types=1);

namespace Laraflow\Support;

use Throwable;

/**
 * The default: messages pass through unchanged.
 */
class PassthroughRedactor implements ErrorRedactor
{
    public function redact(Throwable $exception, string $message): string
    {
        return $message;
    }
}
