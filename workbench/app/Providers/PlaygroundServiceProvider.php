<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Makes the playground's SQLite database survive being read and written
 * by several processes at once.
 *
 * The playground runs a worker, a traffic generator, and a watch
 * command simultaneously, which SQLite's default rollback journal does
 * not enjoy: readers and the writer block each other and you get
 * "database is locked" instead of a demonstration. WAL lets readers
 * carry on while a write is in flight, and a busy timeout makes the
 * remaining writer-versus-writer contention wait rather than fail.
 *
 * This is a playground fix, not advice. Laraflow's concurrency
 * guarantees rest on single-statement compare-and-set updates, which
 * are correct on every supported engine — but SQLite still only has one
 * writer, so watching two workers genuinely race is a job for Postgres
 * or MySQL.
 */
class PlaygroundServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() !== 'sqlite') {
                return;
            }

            // An in-memory database has no WAL and no contention.
            if ($event->connection->getDatabaseName() === ':memory:') {
                return;
            }

            $event->connection->statement('PRAGMA journal_mode = WAL');
            $event->connection->statement('PRAGMA busy_timeout = 5000');
            $event->connection->statement('PRAGMA synchronous = NORMAL');
        });
    }
}
