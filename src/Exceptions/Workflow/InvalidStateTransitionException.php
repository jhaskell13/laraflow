<?php

namespace Laraflow\Exceptions\Workflow;

use BackedEnum;

class InvalidStateTransitionException extends WorkflowException
{
    public static function between(string $subject, BackedEnum $from, BackedEnum $to): self
    {
        return new self(
            "Invalid {$subject} state transition from [{$from->value}] to [{$to->value}]."
        );
    }
}
