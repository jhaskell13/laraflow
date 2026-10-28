<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The scrub tier's scan asks for finished executions that have not
     * been scrubbed yet. With only (status, finished_at) to work from
     * the engine walked every finished row under the cutoff on every
     * run — already-scrubbed rows included — so the cost grew with the
     * size of history rather than with the work left. Leading with
     * payloads_scrubbed_at lets the index skip the scrubbed rows
     * outright: IS NULL is an equality on the leading column on every
     * supported engine, and the cost is the backlog, as retention.md
     * promises.
     *
     * Index builds are not online on PostgreSQL or SQL Server inside a
     * migration transaction; see docs/upgrading.md.
     */
    public function up(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->index(['payloads_scrubbed_at', 'status', 'finished_at'], 'laraflow_executions_scrub_scan_index');
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->dropIndex('laraflow_executions_scrub_scan_index');
        });
    }
};
