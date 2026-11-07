<?php

declare(strict_types=1);

namespace Workbench\App\Soak;

use Generator;

/**
 * An append-only file of JSON lines, one per thing a soak process did
 * or measured: the executions the load generator created, the probes
 * the signaller sent, the timings every process recorded. Written with
 * a lock so processes can share one; read back lazily by the verifier
 * and the report.
 */
final class Ledger
{
    public function __construct(public readonly string $path) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public function append(array $record): void
    {
        file_put_contents($this->path, json_encode($record + ['at' => microtime(true)], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function read(): Generator
    {
        if (! is_file($this->path)) {
            return;
        }

        $handle = fopen($this->path, 'r');

        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Every record in every ledger matching a glob, for the per-process
     * stats files a run accumulates.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public static function readAll(string $glob): Generator
    {
        foreach (glob($glob) ?: [] as $path) {
            yield from (new self($path))->read();
        }
    }
}
