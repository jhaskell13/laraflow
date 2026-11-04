<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Workflow;

/**
 * A fan-out declared more members than laraflow.workflows.max_members
 * allows. Refused before anything is created: a group too wide to
 * cancel is a group too wide to run, and the bound is where that is
 * said rather than discovered as a cascade that never finishes.
 */
final class FanOutTooWideException extends WorkflowException
{
    public static function forCount(string $step, int $position, int $declared, int $max): self
    {
        return new self(
            "Step [{$step}] at position {$position} declared {$declared} fan-out members; "
            ."laraflow.workflows.max_members allows {$max}. Raise the limit if the width is intentional, "
            .'or fan out in smaller groups.'
        );
    }

    public static function notAMember(string $step, int $position, string $type): self
    {
        return new self(
            "Step [{$step}] at position {$position} yielded a {$type} from members(); every member must be a ".\Laraflow\Workflow\FanOut::class.'.'
        );
    }
}
