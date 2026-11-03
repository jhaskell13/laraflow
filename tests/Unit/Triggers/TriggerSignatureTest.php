<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Triggers;

use Illuminate\Config\Repository;
use Laraflow\Triggers\TriggerSignature;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * docs/triggers.md, "The signature": the scheme byte for byte against
 * a fixed vector, so a signer in another language can be checked
 * against the same bytes this verifier expects.
 */
class TriggerSignatureTest extends TestCase
{
    private const KEY = 'test-key-0123456789abcdef0123456789abcdef';

    private const BODY = '{"approved_by":"ops@example.com","note":"Looks right."}';

    public function test_the_signed_string_is_version_timestamp_target_and_body_hash(): void
    {
        $this->assertSame(
            "v1\n1762159200\n42\nmanager-approval\n358076c191a380ca2f8c936aae677078438498f0a68fa824d071f67ab360db82",
            TriggerSignature::signedString(1762159200, 42, 'manager-approval', self::BODY),
        );
    }

    public function test_the_signature_matches_the_published_vector(): void
    {
        $this->assertSame(
            '85de848f360ca5e4d9edb58eedb962bcdbbe211915ae69624c8e70e2c8e79002',
            TriggerSignature::compute(self::KEY, 1762159200, 42, 'manager-approval', self::BODY),
        );

        // An empty body hashes as the SHA-256 of the empty string.
        $this->assertSame(
            '9ee78ed5b2f90890c6889fbb5144301c6090f173356d01c7fc4ba0a1cfe53dd3',
            TriggerSignature::compute(self::KEY, 1762159200, 42, 'manager-approval', ''),
        );
    }

    public function test_the_target_is_in_the_signature_and_the_path_is_not(): void
    {
        $forFortyTwo = TriggerSignature::compute(self::KEY, 1762159200, 42, 'manager-approval', self::BODY);

        $this->assertNotSame($forFortyTwo, TriggerSignature::compute(self::KEY, 1762159200, 43, 'manager-approval', self::BODY), 'Another execution is another signature.');
        $this->assertNotSame($forFortyTwo, TriggerSignature::compute(self::KEY, 1762159200, 42, 'finance-approval', self::BODY), 'Another signal is another signature.');
        $this->assertNotSame($forFortyTwo, TriggerSignature::compute(self::KEY, 1762159201, 42, 'manager-approval', self::BODY), 'Another moment is another signature.');
    }

    public function test_the_header_is_parsed_strictly(): void
    {
        $hex = str_repeat('ab', 32);

        $this->assertSame($hex, TriggerSignature::parse("v1={$hex}"));
        $this->assertSame($hex, TriggerSignature::parse("  v1={$hex}  "), 'Surrounding whitespace is forgiven.');
        $this->assertNull(TriggerSignature::parse($hex), 'The version prefix is required.');
        $this->assertNull(TriggerSignature::parse("v2={$hex}"), 'Only the version this verifier knows.');
        $this->assertNull(TriggerSignature::parse('v1='.strtoupper($hex)), 'Lowercase hex, as specified.');
        $this->assertNull(TriggerSignature::parse('v1='.substr($hex, 2)), 'Sixty-four characters, no fewer.');
    }

    public function test_matches_names_the_key_that_signed_and_null_for_none(): void
    {
        $keys = [self::KEY, 'previous-key-0123456789abcdef0123456789'];

        $current = TriggerSignature::compute(self::KEY, 1762159200, 42, 'manager-approval', self::BODY);
        $previous = TriggerSignature::compute($keys[1], 1762159200, 42, 'manager-approval', self::BODY);
        $foreign = TriggerSignature::compute('retired-key-0123456789abcdef0123456789abc', 1762159200, 42, 'manager-approval', self::BODY);

        $this->assertSame(0, TriggerSignature::matches($current, $keys, 1762159200, 42, 'manager-approval', self::BODY));
        $this->assertSame(1, TriggerSignature::matches($previous, $keys, 1762159200, 42, 'manager-approval', self::BODY));
        $this->assertNull(TriggerSignature::matches($foreign, $keys, 1762159200, 42, 'manager-approval', self::BODY));
    }

    public function test_the_keys_are_read_current_first_and_misconfiguration_is_loud(): void
    {
        $config = new Repository(['laraflow' => ['triggers' => ['key' => self::KEY, 'previous_keys' => ['old-key-0123456789abcdef0123456789abcdef']]]]);

        $this->assertSame([self::KEY, 'old-key-0123456789abcdef0123456789abcdef'], TriggerSignature::keys($config));

        $this->assertLogic(new Repository(['laraflow' => ['triggers' => ['key' => null]]]), 'laraflow.triggers.key is not set');
        $this->assertLogic(new Repository(['laraflow' => ['triggers' => ['key' => '   ']]]), 'laraflow.triggers.key is not set');
        $this->assertLogic(new Repository(['laraflow' => ['triggers' => ['key' => 'too-short']]]), 'at least 32 bytes');
        $this->assertLogic(new Repository(['laraflow' => ['triggers' => ['key' => self::KEY, 'previous_keys' => 'not-a-list']]]), 'previous_keys must be a list');
        $this->assertLogic(new Repository(['laraflow' => ['triggers' => ['key' => self::KEY, 'previous_keys' => [42]]]]), 'previous_keys must hold keys');
    }

    private function assertLogic(Repository $config, string $fragment): void
    {
        try {
            TriggerSignature::keys($config);
        } catch (LogicException $exception) {
            $this->assertStringContainsString($fragment, $exception->getMessage());

            return;
        }

        $this->fail('Expected a LogicException naming the setting.');
    }
}
