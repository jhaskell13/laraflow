<?php

namespace Laraflow\Tests\Unit\Support;

use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Support\DurableValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DurableValueTest extends TestCase
{
    #[DataProvider('durableValues')]
    public function test_it_accepts_json_safe_values(mixed $value): void
    {
        DurableValue::assert($value, 'input');

        $this->addToAssertionCount(1);
    }

    public static function durableValues(): array
    {
        return [
            'null' => [null],
            'bool' => [true],
            'int' => [42],
            'float' => [1.5],
            'string' => ['hello'],
            'empty array' => [[]],
            'list' => [[1, 2, 3]],
            'nested map' => [['customer' => ['id' => 1, 'tags' => ['a', 'b']]]],
        ];
    }

    #[DataProvider('unserializableValues')]
    public function test_it_rejects_values_that_do_not_survive_json(mixed $value): void
    {
        $this->expectException(UnserializableValueException::class);

        DurableValue::assert($value, 'input');
    }

    public static function unserializableValues(): array
    {
        return [
            'object' => [new \stdClass()],
            'closure' => [fn () => null],
            'nested object' => [['customer' => new \stdClass()]],
            'NAN' => [NAN],
            'INF' => [INF],
            'datetime' => [new \DateTimeImmutable()],
        ];
    }

    public function test_the_error_names_the_path_to_the_offending_value(): void
    {
        try {
            DurableValue::assert(['customer' => ['loaded' => new \stdClass()]], 'output');
            $this->fail('Expected UnserializableValueException.');
        } catch (UnserializableValueException $exception) {
            $this->assertStringContainsString('output.customer.loaded', $exception->getMessage());
            $this->assertStringContainsString('stdClass', $exception->getMessage());
        }
    }
}
