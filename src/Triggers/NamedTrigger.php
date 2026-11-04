<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

use Closure;

/**
 * @internal One registered provider endpoint: its slug, the class that
 * verifies the provider's request, and the application's mapping of
 * that request to one SignalIntent or nothing.
 */
final readonly class NamedTrigger
{
    /**
     * @param  class-string<TriggerVerifier>  $verifier
     * @param  Closure(\Illuminate\Http\Request): mixed  $resolver
     */
    public function __construct(
        public string $name,
        public string $verifier,
        public Closure $resolver,
    ) {}
}
