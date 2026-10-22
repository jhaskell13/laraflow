<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * Runs artisan commands capturing the full output buffer.
 *
 * Laravel's PendingCommand::expectsOutputToContain() registers one Mockery
 * matcher per substring and the first matcher that matches a line consumes
 * it, so two expected substrings on the same output line make the test fail
 * even though the output is correct. Asserting against the whole captured
 * buffer avoids that trap.
 */
trait RunsArtisanCommands
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array{status: int, output: string}
     */
    protected function callCommand(string $command, array $parameters = []): array
    {
        $status = Artisan::call($command, $parameters);

        return ['status' => $status, 'output' => Artisan::output()];
    }
}
