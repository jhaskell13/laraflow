<?php

declare(strict_types=1);

namespace Workbench\App\Soak\Process;

/**
 * The processes of one run, by role — workers, schedulers, the
 * signaller, the loops — with what the orchestrator needs to do to
 * them: start another, find out which died, kill one at random, and
 * stop them all in an order that lets the records settle.
 */
final class Fleet
{
    /** @var list<ManagedProcess> */
    private array $processes = [];

    public function add(ManagedProcess $process): ManagedProcess
    {
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    /**
     * @return list<ManagedProcess>
     */
    public function role(string $role): array
    {
        return array_values(array_filter($this->processes, fn (ManagedProcess $p): bool => $p->role === $role));
    }

    /**
     * @return list<ManagedProcess>
     */
    public function running(string $role): array
    {
        return array_values(array_filter($this->role($role), fn (ManagedProcess $p): bool => $p->isRunning()));
    }

    /**
     * @return list<ManagedProcess>
     */
    public function dead(string $role): array
    {
        return array_values(array_filter($this->role($role), fn (ManagedProcess $p): bool => ! $p->isRunning()));
    }

    public function killRandom(string $role): ?ManagedProcess
    {
        $running = $this->running($role);

        if ($running === []) {
            return null;
        }

        $victim = $running[array_rand($running)];
        $victim->kill();

        return $victim;
    }

    public function forget(ManagedProcess $process): void
    {
        $this->processes = array_values(array_filter($this->processes, fn (ManagedProcess $p): bool => $p !== $process));
    }

    public function stopRole(string $role, int $graceSeconds = 10): void
    {
        foreach ($this->role($role) as $process) {
            $process->terminate($graceSeconds);
        }
    }

    public function stopAll(int $graceSeconds = 10): void
    {
        foreach ($this->processes as $process) {
            $process->terminate($graceSeconds);
        }
    }

    /**
     * @return list<ManagedProcess>
     */
    public function all(): array
    {
        return $this->processes;
    }
}
