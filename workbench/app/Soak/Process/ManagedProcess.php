<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Process;

use RuntimeException;

/**
 * One child process the orchestrator owns: started with its output
 * captured to a file, watched, and — because the whole point is that
 * workers die — killed outright with SIGKILL when the fault injector
 * says so, or asked to stop and then killed when the run ends.
 *
 * proc_open with an argument array, so no shell sits between the
 * orchestrator and the process and the pid is the process's own.
 */
final class ManagedProcess
{
    /** @var resource|null */
    private $handle = null;

    private ?int $pid = null;

    private ?int $exitCode = null;

    private float $startedAt = 0.0;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function __construct(
        public readonly string $role,
        public readonly string $name,
        private readonly array $command,
        private readonly array $env,
        public readonly string $logPath,
        private readonly string $cwd,
    ) {}

    public function start(): void
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->logPath, 'a'],
            2 => ['file', $this->logPath, 'a'],
        ];

        $environment = $this->env + self::inherited();

        $handle = proc_open($this->command, $descriptors, $pipes, $this->cwd, $environment);

        if (! is_resource($handle)) {
            throw new RuntimeException("Could not start [{$this->name}].");
        }

        $this->handle = $handle;
        $this->exitCode = null;
        $this->startedAt = microtime(true);

        $status = proc_get_status($handle);
        $this->pid = $status['pid'];
    }

    /**
     * Not a pure read: a process found to have exited is closed here,
     * and its exit code kept.
     *
     * @phpstan-impure
     */
    public function isRunning(): bool
    {
        if ($this->handle === null) {
            return false;
        }

        $status = proc_get_status($this->handle);

        if ($status['running']) {
            return true;
        }

        // The exit code is only reported once; keep the first reading.
        $this->exitCode ??= $status['exitcode'];
        proc_close($this->handle);
        $this->handle = null;

        return false;
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function uptimeSeconds(): float
    {
        return $this->startedAt > 0 ? microtime(true) - $this->startedAt : 0.0;
    }

    /**
     * The fault: no warning, no cleanup, the way a worker host dies.
     */
    public function kill(): void
    {
        if (! $this->isRunning()) {
            return;
        }

        if ($this->handle !== null) {
            proc_terminate($this->handle, 9);
        }
    }

    /**
     * The orderly stop at the end of a run: SIGTERM, a grace period for
     * a worker to finish its job, then SIGKILL for whatever remains.
     */
    public function terminate(int $graceSeconds = 10): void
    {
        $handle = $this->handle;

        if ($handle === null || ! $this->isRunning()) {
            return;
        }

        proc_terminate($handle, 15);

        $deadline = microtime(true) + $graceSeconds;

        while ($this->isRunning() && microtime(true) < $deadline) {
            usleep(100_000);
        }

        $this->kill();
        $this->isRunning();
    }

    /**
     * @return array<string, string>
     */
    private static function inherited(): array
    {
        $inherited = [];

        foreach (getenv() as $name => $value) {
            $inherited[(string) $name] = $value;
        }

        return $inherited;
    }
}
