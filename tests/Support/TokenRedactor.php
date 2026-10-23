<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Support\ErrorRedactor;
use Throwable;

/**
 * Strips sentinel API tokens (tok_...) from exception messages.
 */
class TokenRedactor implements ErrorRedactor
{
    public function redact(Throwable $exception, string $message): string
    {
        return (string) preg_replace('/tok_[A-Za-z0-9]+/', '[redacted]', $message);
    }
}
