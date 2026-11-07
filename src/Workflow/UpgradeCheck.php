<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

/**
 * @internal The result of one upgrade check; `laraflow:upgrade` is the
 * surface. Use the command.
 *
 * Three outcomes, and the difference between the last two is who has
 * to do something:
 *
 * - **ok** — nothing to do.
 * - **review** — something an upgrade should be told about, which the
 *   package cannot decide: a custom binding to re-verify, a removal to
 *   check scripts for. Reported, does not fail.
 * - **action** — the application must change something, or the upgrade
 *   leaves it broken. Fails the command.
 */
final class UpgradeCheck
{
    public const OK = 'ok';

    public const REVIEW = 'review';

    public const ACTION = 'action';

    /**
     * @param  list<string>  $details
     */
    private function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $details = [],
    ) {}

    public static function ok(string $name, string $summary): self
    {
        return new self($name, self::OK, $summary);
    }

    /**
     * @param  list<string>  $details
     */
    public static function review(string $name, string $summary, array $details = []): self
    {
        return new self($name, self::REVIEW, $summary, $details);
    }

    /**
     * @param  list<string>  $details
     */
    public static function action(string $name, string $summary, array $details = []): self
    {
        return new self($name, self::ACTION, $summary, $details);
    }

    public function needsAction(): bool
    {
        return $this->status === self::ACTION;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'summary' => $this->summary,
            'details' => $this->details,
        ];
    }
}
