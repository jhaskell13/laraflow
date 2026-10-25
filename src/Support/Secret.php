<?php

declare(strict_types=1);

namespace Laraflow\Support;

use JsonSerializable;
use Laraflow\Exceptions\SecretRedactedException;
use SensitiveParameter;
use Stringable;
use WeakMap;

/**
 * A credential that cannot be printed by accident.
 *
 * Every way PHP has of turning an object into text — string context,
 * `json_encode`, `print_r`, `var_dump`, `var_export`, `serialize`, an
 * exception renderer walking an object graph — yields `[redacted]`.
 * The value is reached only by calling reveal(), which is a deliberate
 * act at the one place it is needed: composing the outbound request.
 *
 * The value is not stored on the object. `var_export` and an `(array)`
 * cast read properties directly, ignoring `__debugInfo`, so a private
 * property would still leak through them; a WeakMap keyed by instance
 * has nothing for them to read. A serialized Secret carries no value
 * either, and reveal() on the unserialized copy throws — a credential
 * that rode through a queue payload or a cache would otherwise sit in
 * that store in clear text.
 */
final class Secret implements JsonSerializable, Stringable
{
    public const REDACTED = '[redacted]';

    /**
     * @var WeakMap<self, string>|null
     */
    private static ?WeakMap $values = null;

    public function __construct(#[SensitiveParameter] string $value)
    {
        self::values()[$this] = $value;
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        // Deliberately nothing: an unserialized Secret has no value.
    }

    /**
     * Not cloneable. A clone would be a new WeakMap key with no value,
     * and an immutable credential has no reason to be copied: pass the
     * instance.
     */
    private function __clone() {}

    /**
     * Wrap a configuration value, treating null and blank as absent.
     */
    public static function from(#[SensitiveParameter] mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        return trim($value) === '' ? null : new self($value);
    }

    /**
     * The credential itself. Call this where the request is built and
     * nowhere else.
     *
     * @throws SecretRedactedException when this instance was unserialized and so never had a value.
     */
    public function reveal(): string
    {
        return self::values()[$this] ?? throw new SecretRedactedException(
            'This secret was redacted by serialization and has no value. '
            .'Resolve credentials where they are used rather than carrying them across processes.'
        );
    }

    /**
     * Constant-time comparison; never compare revealed strings with `===`.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->reveal(), $other->reveal());
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * @return WeakMap<self, string>
     */
    private static function values(): WeakMap
    {
        /** @var WeakMap<self, string> $map */
        $map = self::$values ??= new WeakMap;

        return $map;
    }
}
