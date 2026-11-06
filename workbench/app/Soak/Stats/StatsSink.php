<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Stats;

/**
 * Where a soak process writes what it measured: one JSON object per
 * line, appended to the file the orchestrator named for it, flushed in
 * small batches so a worker killed mid-run loses little.
 *
 * Without a path it discards everything, which is what the playground
 * and any process outside a run get.
 */
final class StatsSink
{
    private const FLUSH_EVERY = 25;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    public function __construct(private readonly ?string $path)
    {
        if ($this->path !== null) {
            register_shutdown_function($this->flush(...));
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(string $op, string $outcome, ?float $ms = null, array $extra = []): void
    {
        if ($this->path === null) {
            return;
        }

        $this->buffer[] = ['op' => $op, 'outcome' => $outcome, 'ms' => $ms, 'at' => microtime(true)] + $extra;

        if (count($this->buffer) >= self::FLUSH_EVERY) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->path === null || $this->buffer === []) {
            return;
        }

        $lines = '';

        foreach ($this->buffer as $record) {
            $lines .= json_encode($record, JSON_THROW_ON_ERROR)."\n";
        }

        $this->buffer = [];

        file_put_contents($this->path, $lines, FILE_APPEND | LOCK_EX);
    }
}
