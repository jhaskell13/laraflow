<?php

declare(strict_types=1);

namespace Laraflow\Console;

use Illuminate\Console\Command;
use Laraflow\Console\Concerns\OutputsJson;
use Laraflow\Workflow\UpgradeCheck;
use Laraflow\Workflow\UpgradeInspection;

/**
 * @internal The command class is implementation. What 1.0 freezes is the
 * command's name, its options, and the shape of its `--json` document —
 * see docs/public-api.md. Nothing should type-hint or extend this class.
 */
final class UpgradeCommand extends Command
{
    use OutputsJson;

    protected $signature = 'laraflow:upgrade
        {--json : Print one JSON document instead of the report}';

    protected $description = 'Check an installed application against this version of Laraflow and name what it must change';

    public function handle(UpgradeInspection $inspection): int
    {
        $checks = $inspection->run();
        $action = array_values(array_filter($checks, static fn (UpgradeCheck $check): bool => $check->needsAction()));

        $document = [
            'checks' => array_map(static fn (UpgradeCheck $check): array => $check->toArray(), $checks),
            'needs_action' => $action !== [],
        ];

        // Non-zero when the application must change something, so this
        // can gate a deploy the way `laraflow:connectors --check` does.
        return $this->respond(
            $document,
            fn () => $this->report($checks, $action),
            $action === [] ? self::SUCCESS : self::FAILURE,
        );
    }

    /**
     * @param  list<UpgradeCheck>  $checks
     * @param  list<UpgradeCheck>  $action
     */
    private function report(array $checks, array $action): void
    {
        $this->components->info('Laraflow upgrade check');

        foreach ($checks as $check) {
            $this->components->twoColumnDetail(
                $this->label($check).' '.$check->name,
                $check->summary,
            );

            foreach ($check->details as $detail) {
                $this->line('    <fg=gray>'.$detail.'</>');
            }
        }

        $this->newLine();

        if ($action === []) {
            $this->components->info('Nothing to do: `composer update` and `php artisan migrate` are the whole upgrade.');

            return;
        }

        $this->components->error(sprintf(
            '%d check(s) need action before upgrading. See docs/upgrading.md.',
            count($action),
        ));
    }

    private function label(UpgradeCheck $check): string
    {
        return match ($check->status) {
            UpgradeCheck::OK => '<fg=green>ok</>',
            UpgradeCheck::ACTION => '<fg=red>action</>',
            default => '<fg=yellow>review</>',
        };
    }
}
