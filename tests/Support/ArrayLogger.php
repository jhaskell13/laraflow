<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR-3 logger for asserting what the package logs.
 */
class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasRecordContaining(string $level, string $fragment): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $fragment)) {
                return true;
            }
        }

        return false;
    }
}
