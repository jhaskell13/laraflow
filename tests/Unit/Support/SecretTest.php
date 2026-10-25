<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Support;

use Error;
use Laraflow\Exceptions\SecretRedactedException;
use Laraflow\Support\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

class SecretTest extends TestCase
{
    public function test_reveal_is_the_only_way_to_the_value(): void
    {
        $secret = new Secret('ghp_super_secret');

        $this->assertSame('ghp_super_secret', $secret->reveal());
    }

    /**
     * Every rendering PHP offers. var_export and the array cast read
     * properties directly, bypassing __debugInfo, which is why the
     * value is not a property.
     */
    public function test_no_rendering_contains_the_value(): void
    {
        $secret = new Secret('ghp_super_secret');

        $renderings = [
            'string context' => (string) $secret,
            'interpolation' => "Bearer {$secret}",
            'json_encode' => (string) json_encode(['token' => $secret]),
            'print_r' => print_r($secret, true),
            'var_export' => var_export($secret, true),
            'serialize' => serialize($secret),
            'array cast' => (string) json_encode((array) $secret),
            'get_object_vars' => (string) json_encode(get_object_vars($secret)),
            'var_dump' => $this->varDump($secret),
        ];

        foreach ($renderings as $how => $rendered) {
            $this->assertStringNotContainsString('ghp_super_secret', $rendered, "{$how} leaked the value.");
        }

        $this->assertSame(Secret::REDACTED, (string) $secret);
        $this->assertSame('{"token":"[redacted]"}', json_encode(['token' => $secret]));
    }

    public function test_an_unserialized_secret_has_no_value_and_says_so(): void
    {
        $copy = unserialize(serialize(new Secret('ghp_super_secret')));

        $this->assertInstanceOf(Secret::class, $copy);
        $this->expectException(SecretRedactedException::class);

        $copy->reveal();
    }

    public function test_a_secret_cannot_be_cloned(): void
    {
        $secret = new Secret('ghp_super_secret');

        $this->expectException(Error::class);

        clone $secret;
    }

    public function test_equals_compares_values(): void
    {
        $this->assertTrue((new Secret('a'))->equals(new Secret('a')));
        $this->assertFalse((new Secret('a'))->equals(new Secret('b')));
    }

    #[DataProvider('absentValues')]
    public function test_from_treats_null_and_blank_as_absent(mixed $value): void
    {
        $this->assertNull(Secret::from($value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function absentValues(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['  '],
            'array' => [['not' => 'scalar']],
        ];
    }

    public function test_from_wraps_scalars_and_passes_secrets_through(): void
    {
        $this->assertSame('123', Secret::from(123)?->reveal());
        $this->assertSame('abc', Secret::from('abc')?->reveal());

        $secret = new Secret('abc');

        $this->assertSame($secret, Secret::from($secret));
    }

    /**
     * The attribute is what keeps the value out of stack traces when
     * zend.exception_ignore_args is off; its presence is the contract.
     */
    public function test_every_entry_point_marks_its_value_sensitive(): void
    {
        foreach (['__construct', 'from'] as $method) {
            $parameter = (new ReflectionMethod(Secret::class, $method))->getParameters()[0];

            $this->assertNotEmpty(
                $parameter->getAttributes(SensitiveParameter::class),
                "Secret::{$method}() must mark its value #[SensitiveParameter].",
            );
        }
    }

    protected function varDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
