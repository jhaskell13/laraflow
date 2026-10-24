<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Workflow;

use Laraflow\Exceptions\Workflow\InvalidExecutionOptionsException;
use Laraflow\Workflow\ExecutionOptions;
use PHPUnit\Framework\TestCase;

class ExecutionOptionsTest extends TestCase
{
    public function test_it_defaults_to_carrying_nothing(): void
    {
        $options = new ExecutionOptions;

        $this->assertNull($options->correlationId);
        $this->assertNull($options->tenantId);
        $this->assertTrue($options->isEmpty());
    }

    public function test_it_keeps_the_identifiers_it_is_given(): void
    {
        $options = new ExecutionOptions('req-abc123', 'acme');

        $this->assertSame('req-abc123', $options->correlationId);
        $this->assertSame('acme', $options->tenantId);
        $this->assertFalse($options->isEmpty());
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $options = new ExecutionOptions("  req-abc123\t", ' acme ');

        $this->assertSame('req-abc123', $options->correlationId);
        $this->assertSame('acme', $options->tenantId);
    }

    public function test_a_trailing_newline_is_trimmed_rather_than_rejected(): void
    {
        // Trimming happens first, so a value that merely arrived with a
        // trailing line ending is usable rather than an error. Only
        // control characters *within* the identifier are hostile.
        $this->assertSame('req-abc123', (new ExecutionOptions("req-abc123\r\n"))->correlationId);
    }

    public function test_a_blank_identifier_is_treated_as_absent(): void
    {
        // `new ExecutionOptions(correlationId: $request->header('X-Request-Id'))`
        // must be safe when the header is missing: an empty string in an
        // indexed column matches nothing and means nothing.
        foreach (['', '   ', "\n"] as $blank) {
            $this->assertNull((new ExecutionOptions($blank, $blank))->correlationId);
            $this->assertNull((new ExecutionOptions($blank, $blank))->tenantId);
        }
    }

    public function test_an_identifier_at_the_length_limit_is_accepted(): void
    {
        $limit = str_repeat('a', ExecutionOptions::MAX_LENGTH);

        $this->assertSame($limit, (new ExecutionOptions($limit))->correlationId);
    }

    public function test_an_overlong_identifier_is_rejected_rather_than_truncated(): void
    {
        $this->expectException(InvalidExecutionOptionsException::class);
        $this->expectExceptionMessage('correlationId');

        // Silent truncation by the database would leave an identifier
        // that correlates with nothing.
        new ExecutionOptions(str_repeat('a', ExecutionOptions::MAX_LENGTH + 1));
    }

    public function test_the_overlong_message_names_the_offending_option(): void
    {
        $this->expectException(InvalidExecutionOptionsException::class);
        $this->expectExceptionMessage('tenantId');

        new ExecutionOptions(tenantId: str_repeat('a', ExecutionOptions::MAX_LENGTH + 1));
    }

    public function test_control_characters_are_rejected(): void
    {
        // These values reach log records and logging context, where a
        // newline would let a crafted identifier forge a second line.
        foreach (["req\nFAKE LOG LINE", "req\rFAKE", "req\x00id", "req\x1b[31m"] as $hostile) {
            try {
                new ExecutionOptions($hostile);
                $this->fail('Expected a control character to be rejected: '.json_encode($hostile));
            } catch (InvalidExecutionOptionsException $exception) {
                $this->assertStringContainsString('control characters', $exception->getMessage());
            }
        }
    }

    public function test_ordinary_punctuation_and_unicode_are_allowed(): void
    {
        $options = new ExecutionOptions('urn:uuid:9f8e-7d6c/äöü', 'tenant-a_b.c');

        $this->assertSame('urn:uuid:9f8e-7d6c/äöü', $options->correlationId);
        $this->assertSame('tenant-a_b.c', $options->tenantId);
    }
}
