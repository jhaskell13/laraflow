<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Support;

use DateTimeImmutable;
use Laraflow\Exceptions\Workflow\PayloadTooLargeException;
use Laraflow\Exceptions\Workflow\UnserializableValueException;
use Laraflow\Support\DurableValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class DurableValueTest extends TestCase
{
    // ------------------------------------------------------------------
    // Size limit
    // ------------------------------------------------------------------

    public function test_a_null_limit_accepts_any_size(): void
    {
        DurableValue::assertSize(['blob' => str_repeat('a', 100_000)], 'input', null);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_payload_at_the_limit_is_accepted(): void
    {
        // json_encode('aaaa') is 6 bytes: the quotes count, because the
        // column stores the encoded form.
        DurableValue::assertSize('aaaa', 'output', 6);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_payload_over_the_limit_is_rejected(): void
    {
        $this->expectException(PayloadTooLargeException::class);
        $this->expectExceptionMessage('[output]');

        DurableValue::assertSize('aaaa', 'output', 5);
    }

    public function test_the_message_names_the_actual_size_and_the_limit(): void
    {
        try {
            DurableValue::assertSize(str_repeat('a', 2000), 'context', 100);
            $this->fail('Expected rejection.');
        } catch (PayloadTooLargeException $exception) {
            $this->assertStringContainsString('2,002 bytes', $exception->getMessage());
            $this->assertStringContainsString('limit of 100', $exception->getMessage());
        }
    }

    public function test_size_is_measured_on_the_encoded_form_not_the_php_value(): void
    {
        // Three one-character strings in an array encode to
        // ["a","b","c"] — 13 bytes, not 3.
        DurableValue::assertSize(['a', 'b', 'c'], 'input', 13);

        $this->expectException(PayloadTooLargeException::class);

        DurableValue::assertSize(['a', 'b', 'c'], 'input', 12);
    }

    public function test_check_enforces_the_type_contract_before_the_size(): void
    {
        // An unrepresentable value is a serialization fault whatever
        // its size, and must be reported as one.
        $this->expectException(UnserializableValueException::class);

        DurableValue::check(['bad' => new stdClass], 'input', 1);
    }

    public function test_check_enforces_the_size_for_a_valid_value(): void
    {
        $this->expectException(PayloadTooLargeException::class);

        DurableValue::check(['ok' => 'but too big'], 'input', 5);
    }

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
            'object' => [new stdClass],
            'closure' => [fn () => null],
            'nested object' => [['customer' => new stdClass]],
            'NAN' => [NAN],
            'INF' => [INF],
            'datetime' => [new DateTimeImmutable],
        ];
    }

    public function test_the_error_names_the_path_to_the_offending_value(): void
    {
        try {
            DurableValue::assert(['customer' => ['loaded' => new stdClass]], 'output');
            $this->fail('Expected UnserializableValueException.');
        } catch (UnserializableValueException $exception) {
            $this->assertStringContainsString('output.customer.loaded', $exception->getMessage());
            $this->assertStringContainsString('stdClass', $exception->getMessage());
        }
    }
}
