<?php

declare(strict_types=1);

namespace Laraflow\Tests\Feature\Triggers;

use Laraflow\Exceptions\Workflow\InvalidSignalException;
use Laraflow\Tests\Support\ApprovalWorkflow;
use Laraflow\Tests\TestCase;
use Laraflow\Workflow\Wait;
use Laraflow\Workflow\WorkflowRunner;

/**
 * Milestone 12's security review.
 *
 * A signal name is persisted, written to Laravel's log context, put on
 * a lifecycle event, rendered into `--json`, and shown on the
 * dashboard — the same sinks a correlation or tenant id reaches, and
 * those have been guarded since they were added. Signal names were
 * not, so a newline could forge a log line and a byte sequence that is
 * not UTF-8 could make `json_encode()` fail in whichever sink read the
 * record first.
 */
class SignalNameSafetyTest extends TestCase
{
    public function test_a_signal_name_carrying_a_newline_is_refused(): void
    {
        $this->expectException(InvalidSignalException::class);
        $this->expectExceptionMessage('control characters');

        Wait::forSignal("approval\nlevel=admin");
    }

    public function test_a_signal_name_carrying_a_null_byte_is_refused(): void
    {
        $this->expectException(InvalidSignalException::class);

        Wait::forSignal("approval\x00");
    }

    public function test_a_signal_name_that_is_not_utf8_is_refused(): void
    {
        $this->expectException(InvalidSignalException::class);
        $this->expectExceptionMessage('valid UTF-8');

        Wait::forSignal('approval'.chr(0xFF).chr(0xFE));
    }

    public function test_an_ordinary_signal_name_is_still_accepted(): void
    {
        $this->assertSame('manager-approval', Wait::forSignal('manager-approval')->signal);
        $this->assertSame('café.approved', Wait::forSignal('café.approved')->signal);
    }

    /**
     * The public entry refuses the same names, so nothing reaches the
     * runtime through `signal()` that `Wait` would have refused.
     */
    public function test_the_public_signal_entry_refuses_an_unsafe_name(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new ApprovalWorkflow);

        $this->expectException(InvalidSignalException::class);

        $this->app->make(WorkflowRunner::class)->signal($execution->id, "approval\r\nINFO: forged");
    }

    /**
     * The guard rejects before persistence, so no record can hold a
     * name that would break the reader of the record.
     */
    public function test_no_unsafe_name_can_reach_a_durable_record(): void
    {
        $execution = $this->app->make(WorkflowRunner::class)->start(new ApprovalWorkflow);

        foreach (["a\nb", "a\x00b", 'a'.chr(0xFF), str_repeat('x', 256)] as $name) {
            try {
                $this->app->make(WorkflowRunner::class)->signal($execution->id, $name);
                $this->fail('Expected ['.bin2hex($name).'] to be refused.');
            } catch (InvalidSignalException) {
                // Refused, as it must be.
            }
        }

        $this->assertSame(0, (int) $this->app['db']->table('laraflow_signal_inbox')->count());
    }
}
