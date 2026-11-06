<?php

declare(strict_types=1);

namespace Workbench\App\Soak;

/**
 * One invariant's verdict: what was checked, how many rows broke it,
 * and enough detail to start on the first few. Informational findings
 * report a number without judging it.
 */
final class Finding
{
    /**
     * @param  list<string>  $details
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly int $violations,
        public readonly array $details = [],
        public readonly bool $informational = false,
    ) {}

    public function passed(): bool
    {
        return $this->informational || $this->violations === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'violations' => $this->violations,
            'passed' => $this->passed(),
            'informational' => $this->informational,
            'details' => array_slice($this->details, 0, 25),
        ];
    }
}
