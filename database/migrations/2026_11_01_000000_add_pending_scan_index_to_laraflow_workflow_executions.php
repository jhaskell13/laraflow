<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The opt-in pending scan asks for `pending` executions created
     * before a cutoff. The stale and due scans each had an index shaped
     * for their predicate — (status, claimed_at), (status, next_retry_at)
     * — but this one had only the single-column created_at index, so
     * an engine either walked every row under the cutoff and filtered
     * by status, or walked every pending row and filtered by time. With
     * many held-back pending executions (the very situation the scan is
     * opt-in for) the cost grew with their number rather than with the
     * lost dispatches it was looking for. (status, created_at) serves
     * the predicate the way its siblings are served; the Milestone 7
     * audit recorded the gap.
     *
     * An index build is not online on PostgreSQL inside a migration
     * transaction, nor on SQL Server outside Enterprise; see
     * docs/upgrading.md.
     */
    public function up(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'laraflow_executions_pending_scan_index');
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->dropIndex('laraflow_executions_pending_scan_index');
        });
    }
};
