<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trace an execution was started in (docs/tracing.md): a W3C
     * `traceparent`, captured when the execution is created and read by
     * every run, so a trace crosses the queue on the execution rather
     * than in a job payload a retry or a recovery scan re-creates.
     *
     * Version 00 of the format is exactly 55 characters; nothing else is
     * accepted when it is written. Nullable — an execution started with
     * no trace active, or before tracing was enabled, has none — and
     * unindexed: it is only ever read with the row it belongs to.
     *
     * A nullable column with no default and no index: metadata-only on
     * every supported engine, safe against live workers.
     */
    public function up(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->string('trace_context', 55)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->dropColumn('trace_context');
        });
    }
};
